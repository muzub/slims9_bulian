<?php

class CreateSafeImportTables extends \SLiMS\Migration\Migration
{
    public function up()
    {
        \SLiMS\DB::getInstance()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `plugin_safe_import_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_name` varchar(150) NOT NULL,
  `import_type` varchar(20) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `temp_file` varchar(255) DEFAULT NULL,
  `format_options` longtext DEFAULT NULL,
  `status` varchar(30) NOT NULL,
  `processed_rows` int(11) NOT NULL DEFAULT 0,
  `success_rows` int(11) NOT NULL DEFAULT 0,
  `skipped_rows` int(11) NOT NULL DEFAULT 0,
  `rollback_rows` int(11) NOT NULL DEFAULT 0,
  `notes` longtext DEFAULT NULL,
  `error_message` longtext DEFAULT NULL,
  `uid` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `rolled_back_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `safe_import_status_idx` (`status`),
  KEY `safe_import_uid_idx` (`uid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        \SLiMS\DB::getInstance()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `plugin_safe_import_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `entity_type` varchar(20) NOT NULL,
  `action_type` varchar(20) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `entity_key` varchar(100) DEFAULT NULL,
  `snapshot` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `safe_import_session_idx` (`session_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        \SLiMS\DB::getInstance()->exec('DROP TABLE IF EXISTS `plugin_safe_import_entries`');
        \SLiMS\DB::getInstance()->exec('DROP TABLE IF EXISTS `plugin_safe_import_sessions`');
    }
}
