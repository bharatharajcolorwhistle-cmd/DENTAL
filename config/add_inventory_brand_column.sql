SET @dcmt_db := DATABASE();

SET @dcmt_col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @dcmt_db
    AND TABLE_NAME = 'dcmt_inventory'
    AND COLUMN_NAME = 'dcmt_brand'
);

SET @dcmt_sql := IF(
  @dcmt_col_exists = 0,
  'ALTER TABLE dcmt_inventory ADD COLUMN dcmt_brand VARCHAR(100) NULL AFTER dcmt_name',
  'SELECT \"dcmt_brand column already exists\" AS message'
);

PREPARE dcmt_stmt FROM @dcmt_sql;
EXECUTE dcmt_stmt;
DEALLOCATE PREPARE dcmt_stmt;
