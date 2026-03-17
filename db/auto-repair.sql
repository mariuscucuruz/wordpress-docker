--
-- Repair and optimize all tables
--

SELECT CONCAT('CHECK TABLE ', table_name, '; ANALYZE TABLE ', table_name, '; OPTIMIZE TABLE ', table_name, ';') FROM information_schema.tables WHERE table_schema = '${MYSQL_DATABASE}';

ALTER TABLE wp_posts MODIFY id INT NOT NULL AUTO_INCREMENT, MODIFY post_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, MODIFY post_date_gmt DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,   MODIFY post_modified DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,   MODIFY post_modified_gmt DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

SELECT CONCAT('CHECK TABLE ', table_name, '; ANALYZE TABLE ', table_name, '; OPTIMIZE TABLE ', table_name, ';') FROM information_schema.tables WHERE table_schema = '${MYSQL_DATABASE}';
