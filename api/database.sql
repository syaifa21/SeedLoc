-- Database: seedlocm_apk
-- Create tables for SeedLoc application

-- Projects table
CREATE TABLE IF NOT EXISTS `projects` (
  `projectId` int(11) NOT NULL PRIMARY KEY,
  `activityName` varchar(255) NOT NULL,
  `locationName` varchar(255) NOT NULL,
  `officers` text NOT NULL,
  `status` varchar(50) DEFAULT 'Active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Geotags table
CREATE TABLE IF NOT EXISTS `geotags` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `projectId` int(11) NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `locationName` varchar(255) NOT NULL,
  `timestamp` varchar(50) NOT NULL,
  `itemType` varchar(255) NOT NULL,
  `condition` varchar(50) NOT NULL,
  `details` text NOT NULL,
  `photoPath` varchar(500) DEFAULT '',
  `isSynced` tinyint(1) DEFAULT 1,
  `deviceId` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`projectId`) REFERENCES `projects`(`projectId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create indexes for better performance
CREATE INDEX `idx_projectId` ON `geotags`(`projectId`);
CREATE INDEX `idx_isSynced` ON `geotags`(`isSynced`);
CREATE INDEX `idx_timestamp` ON `geotags`(`timestamp`);
