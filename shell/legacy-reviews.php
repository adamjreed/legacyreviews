<?php

require_once 'abstract.php';

class Reed_Shell_Legacy_Reviews extends Mage_Shell_Abstract
{
    public function run()
    {
        if($this->getArg('reviews') && $this->getArg('ratings')) {
            $this->_importData();
        }else {
            echo $this->usageHelp();
        }

        return $this;
    }

    protected function _importData()
    {
        $reviewsFile = $this->getArg('reviews');
        $ratingsFile = $this->getArg('ratings');
        $mapping = $this->getArg('mapping');

        $baseDir = Mage::getBaseDir();
        $reviewPath = $baseDir . '/' . $reviewsFile;
        $ratingPath = $baseDir . '/' . $ratingsFile;
        if($mapping) {
            $mapping = $this->_parseMapping($mapping);
        }
        if(file_exists($reviewPath) && file_exists($ratingPath)) {
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

                //double save because review model checks for an id and resets this on new objects - i know it sucks
                $reviewModel->setCreatedAt($review['created_at'])->save();

                $reviewRatings = array_filter($ratings, function($rating) use ($review) {
                    return $rating['review_id'] == $review['review_id'];
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

            echo 'Successfully imported ' . $count . ' reviews';
        }
        else {
            echo "One of the files doesn't exist.";
        }

        return $this;
    }

    protected function _getCsvWithHeaders($csv) {
        $headers = array_shift($csv);

        $result = array_walk($csv, function(&$array, $key, $userdata) {
            $array = array_combine($userdata, $array);
        }, $headers);

        if(!$result) {
            return array();
        }

        return $csv;
    }
    protected function _parseMapping($mapping) {
        if(!$mapping || $mapping == "") {
            return null;
        }

        $array = explode(',', $mapping);

        return array_reduce($array, function($result, $item){
            list($key, $value) = explode('=', $item);
            $result[$key] = $value;
            return $result;
        }, array());
    }

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:
    php legacy-reviews.php --reviews REVIEWS_FILE_PATH --ratings RATINGS_FILE_PATH  [--mapping MAPPING] | [--help | -h | help]
    --reviews         The reviews csv file path, relative to the magento directory
    --ratings         The ratings file path, relative to the magento directory
    --mapping         If product IDs are different, the mapping of product IDs from the legacy file to the website
                      (old_id=new_id,old_id=new_id...)
    -h                Short alias for help
    help              This help
USAGE;
    }
}

$shell = new Reed_Shell_Legacy_Reviews();
$shell->run();
