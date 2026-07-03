<?php
require_once 'config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `project_groups` (
        `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name` varchar(255) NOT NULL,
        `description` text DEFAULT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $stmt = $pdo->query("SHOW COLUMNS FROM `projects` LIKE 'groupId'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `projects` ADD COLUMN `groupId` INT(11) DEFAULT NULL;");
        $pdo->exec("ALTER TABLE `projects` ADD CONSTRAINT `fk_project_group` FOREIGN KEY (`groupId`) REFERENCES `project_groups`(`id`) ON DELETE SET NULL;");
    }
    
    echo "Migration successful.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
