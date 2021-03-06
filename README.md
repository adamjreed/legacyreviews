# legacyreviews
A shell script for Magento that imports product reviews from a CSV file.

## Purpose
This script is designed to quickly migrate reviews from one Magento database to another. Useful for migrating from stage to production or making catalog changes that involve deleting products. You can import reviews with the same products IDs or provide a mapping to the script to translate products IDs from old to new.

## Usage
There are 4 parameters you can specify for this script:

* reviews - This is the path to the reviews file, relative to the Magento root.
* ratings - This is the path to the ratings file, relative to the Magento root.
* mapping - This is an optional mapping of product IDs from those contained in the import files to those in the Magento database. It is expected as a string in the following format:
oldId=newId,oldId2=newId2,...,oldIdN=newIdN
* verbose - The level of logging verbosity with which to run this script. Accepted values are "error", "info", and "all". Defaults to "all" if you just pass the flag.

Here is an example of a fully-formed command:
```
php legacy-reviews.php --reviews var/legacyreviews/reviews.csv --ratings var/legacyreviews/ratings.csv --mapping "50=65,55=70,60=75,65=80" --verbose
```

## Import Files
### Reviews
Expected Headers:
```
review_id,entity_pk_value,created_at,status_id,customer_id,store_id,title,nickname,detail
```
Example SQL Query:
```
SELECT r.review_id, r.entity_pk_value, r.created_at, r.status_id, rd.customer_id, rd.store_id,rd.title,rd.nickname,rd.detail
INTO OUTFILE 'reviews.csv'
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
FROM review r
LEFT JOIN review_detail rd ON r.review_id = rd.review_id;
```

### Ratings
Expected Headers:
```
rating_id,review_id,customer_id,option_id,entity_pk_value
```
Example SQL Query:
```
SELECT rating_id, review_id, customer_id, option_id, entity_pk_value
INTO OUTFILE 'ratings.csv'
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
FROM rating_option_vote;
```
