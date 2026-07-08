-- Migrate registry table from utf8mb3 to utf8mb4
ALTER TABLE `registry`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;