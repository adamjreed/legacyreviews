<?php

require_once 'abstract.php';

/**
 * This class allows you to import an archive of reviews from another Magento instance.
 * Useful for migrating storefronts in staging environments or merging catalogs.
 */
class Reed_Shell_LegacyReviews extends Mage_Shell_Abstract {

    const VERBOSITY_ERROR     = 0;
    const VERBOSITY_INFO      = 1;
    const VERBOSITY_ALL       = 2;

    /**
     * The level of logging verbosity with which to run this script.
     * @param int
     */
    protected $verboseMode;

    /**
     * Run the script. If the appropriate params are not present, show the usage help.
     */
    public function run()
    {
        $this->_setVerbosity($this->getArg('verbose'));

        $reviews = $this->getArg('reviews');
        $ratings = $this->getArg('ratings');
        $mapping = $this->getArg('mapping');

        if($reviews === false || $ratings === false) {
            $this->_writeLn("One or more required flags were not passed. See usage help.", self::VERBOSITY_ERROR);
            echo $this->usageHelp();
            exit(1);
        }

        try {
            if($mapping !== false) {
                $mapping = $this->_parseMapping($mapping);
            }

            $rowsAdded = $this->_importData($reviews, $ratings, $mapping);

            if($rowsAdded === 0) {
                throw new Reed_Shell_Exception('No reviews were added.');
            }

            $this->_writeLn("Successfully imported {$rowsAdded} reviews.", self::VERBOSITY_INFO);
        } catch(Reed_Shell_Exception $e) {
            $this->_writeLn($e->getMessage(), self::VERBOSITY_ERROR);
            echo $this->usageHelp();
            exit(1);
        } catch(Exception $e) {
            $this->_writeLn(
                array(
                    "Unhandled exception occurred:",
                    $e->getMessage()
                ),
                self::VERBOSITY_ERROR
            );
            exit(1);
        }

        exit(0);
    }

    /**
     * Import review and ratings csv data into the current Magento instance.
     *
     * @param  string        $reviews
     * @param  string        $ratings
     * @param  array|boolean $mapping
     * @return int           $count
     *
     * @throws Reed_Shell_Exception
     */
    protected function _importData($reviews, $ratings, $mapping)
    {
        $baseDir = Mage::getBaseDir();
        $reviewPath = $baseDir . PATH_SEPARATOR . $reviews;
        $ratingPath = $baseDir . PATH_SEPARATOR . $ratings;

        if(!file_exists($reviewPath) || !file_exists($ratingPath)) {
            throw new Reed_Shell_Exception('One or more import files does not exist. Please double check their location and permissions.');
        }

        $csv = new Varien_File_Csv();
        $reviews = $this->_getCsvWithHeaders($csv->getData($reviewPath));
        $ratings = $this->_getCsvWithHeaders($csv->getData($ratingPath));

        $count = 0;
        foreach($reviews as $review) {
            $reviewModel = Mage::getModel('review/review');
            $reviewModel
                ->setData(
                    array(
                        'entity_id' => $reviewModel->getEntityIdByCode(Mage_Review_Model_Review::ENTITY_PRODUCT_CODE),
                        'entity_pk_value' => $mapping ? $mapping[$review['entity_pk_value']] : $review['entity_pk_value'],
                        'status_id' => $review['status_id'],
                        'customer_id' => null,
                        'title' => $review['title'],
                        'nickname' => $review['nickname'],
                        'detail' => $review['detail'],
                        'store_id' => $review['store_id'],
                        'stores' => array($review['store_id'])
                    )
                )
                ->save();

            //we have to double save here because Magento's review model checks for an id and resets the timestamps
            //on new objects - not great for performance, but there's no other way to import the created time correctly
            $reviewModel->setCreatedAt($review['created_at'])->save();

            $reviewRatings = array_filter($ratings, function($rating) use ($review) {
                return $rating['review_id'] === $review['review_id'];
            });

            foreach($reviewRatings as $rating) {
                Mage::getModel('rating/rating')
                    ->setRatingId($rating['rating_id'])
                    ->setReviewId($reviewModel->getId())
                    ->setCustomerId($rating['customer_id'])
                    ->addOptionVote($rating['option_id'], $mapping ? $mapping[$rating['entity_pk_value']] : $rating['entity_pk_value']);
            }

            $reviewModel->aggregate();

            $count++;
        }

        return $count;
    }

    /**
     * Given an array containing csv data with headers in the first row, parse it out into an associative array.
     *
     * @param  array $csv
     * @return array $csv
     */
    protected function _getCsvWithHeaders($csv) {
        $headers = array_shift($csv);

        $result = array_walk($csv, function(&$array, $key, $headers) {
            $array = array_combine($headers, $array);
        }, $headers);

        if(!$result) {
            return array();
        }

        return $csv;
    }

    /**
     * Parse a comma separated string of key=value ID pairs. If the given string doesn't match
     * this pattern, throw an exception.
     *
     * @param  string $mapping
     * @return array
     * @throws Reed_Shell_Exception
     */
    protected function _parseMapping($mapping) {
        if(!preg_match('/^(\d+=\d+,?)+$/', $mapping)) {
            throw new Reed_Shell_Exception('The mapping string was not properly formatted.');
        }

        return array_reduce(explode(',', $mapping), function($result, $item){
            list($key, $value) = explode('=', $item);
            $result[$key] = $value;
            return $result;
        }, array());
    }

    /**
     * Set the level of verbosity for logging.
     *
     * @param string|bool $verbosity
     */
    protected function _setVerbosity($verbosity) {
        //the extra if statement is required to handle the boolean case
        //because the switch statement in PHP doesn't do strict typing
        if($verbosity === true) {
            $this->verboseMode = self::VERBOSITY_ALL;
        } else {
            switch($verbosity) {
                case 'error':
                    $this->verboseMode = self::VERBOSITY_ERROR;
                    break;
                case 'info':
                    $this->verboseMode = self::VERBOSITY_INFO;
                    break;
                case 'all':
                    $this->verboseMode = self::VERBOSITY_ALL;
            }
        }
    }

    /**
     * Write a line or lines of output to the console.
     *
     * @param string|array $output
     * @param int          $minimumVerbosity
     */
    protected function _writeLn($output, $minimumVerbosity) {
        if($this->verboseMode < $minimumVerbosity) {
            return;
        }

        if(is_array($output)) {
            foreach($output as $line) {
                echo $line . "\n";
            }
        } else {
            echo $output . "\n";
        }
    }

    /**
     * Show usage help.
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:
    php legacy-reviews.php --reviews REVIEWS_FILE_PATH --ratings RATINGS_FILE_PATH  [--mapping MAPPING] | [--help | -h | help]
    --reviews         The reviews csv file path, relative to the magento directory
    --ratings         The ratings csv file path, relative to the magento directory
    --mapping         If product IDs are different, the mapping of product IDs from the legacy file to the website
                      (old_id=new_id,old_id=new_id...)
    --verbose         The level of logging verbosity with which to run this script. Accepted values are "error", "info", and "all".
                      Defaults to "all" if you just pass the flag.
    -h                Short alias for help
    help              This help\n
USAGE;
    }
}

class Reed_Shell_Exception extends Exception {}

$shell = new Reed_Shell_LegacyReviews();
$shell->run();