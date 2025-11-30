-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 30, 2025 at 01:03 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ecowaste`
--

-- --------------------------------------------------------

--
-- Table structure for table `badges`
--

CREATE TABLE `badges` (
  `badge_id` int(11) NOT NULL,
  `badge_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'fas fa-award',
  `points_required` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `badges`
--

INSERT INTO `badges` (`badge_id`, `badge_name`, `description`, `icon`, `points_required`) VALUES
(2, 'Recycling Pro', 'Recycled 10+ items', 'fas fa-recycle', 50),
(3, 'Donation Hero', 'Donated 5+ items', 'fas fa-hand-holding-heart', 75),
(4, 'Project Master', 'Completed 3+ projects', 'fas fa-project-diagram', 100),
(6, 'EcoWaste Beginner', 'Completed your first eco activity', 'fas fa-seedling', 10),
(7, 'Rising Donor', 'Completed your first donation', 'fas fa-hand-holding-heart', 10),
(8, 'Eco Star', 'Completed a recycling project', 'fas fa-star', 15),
(9, 'Donation Starter', 'Donated 1 item', 'fas fa-hand-holding-heart', 20),
(10, 'Donation Champion', 'Donated 15+ items', 'fas fa-gift', 225),
(11, 'Generous Giver', 'Completed 20 donations', 'fas fa-hands-helping', 400),
(12, 'Charity Champion', 'Completed 30 donations', 'fas fa-award', 600),
(13, 'Recycling Starter', 'Recycled 1 item', 'fas fa-recycle', 10),
(14, 'Recycling Expert', 'Recycled 15+ items', 'fas fa-recycle', 150),
(15, 'Zero Waste Hero', 'Created 25 recycling projects', 'fas fa-project-diagram', 375),
(16, 'Earth Saver', 'Created 30 recycling projects', 'fas fa-globe', 450),
(17, 'Eco Pro', 'Completed 20 recycling projects', 'fas fa-seedling', 300),
(18, 'EcoLegend', 'Completed 30 recycling projects', 'fas fa-trophy', 450),
(19, 'EcoWaste Rookie', 'Earned 50+ points', 'fas fa-star', 50),
(20, 'EcoWaste Master', 'Earned 100+ points', 'fas fa-medal', 100),
(21, 'EcoWaste Warrior', 'Earned 200+ points', 'fas fa-trophy', 200),
(22, 'EcoWaste Legend', 'Earned 500+ points', 'fas fa-crown', 500);

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversations`
--

CREATE TABLE `chat_conversations` (
  `conversation_id` int(11) NOT NULL,
  `donation_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `message_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_participants`
--

CREATE TABLE `chat_participants` (
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `last_read_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `comment_id` int(11) NOT NULL,
  `donation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `parent_id` int(11) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `comments`
--

INSERT INTO `comments` (`comment_id`, `donation_id`, `user_id`, `comment_text`, `created_at`, `parent_id`, `edited_at`, `updated_at`) VALUES
(1, 27, 4, 'yow', '2025-09-12 17:21:28', NULL, NULL, '2025-10-15 16:48:58'),
(66, 83, 4, 'ddf', '2025-10-20 15:44:20', NULL, NULL, '2025-10-20 15:44:20'),
(67, 85, 4, 'wow', '2025-10-27 14:06:37', NULL, NULL, '2025-10-27 14:06:37'),
(68, 85, 4, 'sheesh', '2025-10-27 14:06:43', 67, NULL, '2025-10-27 14:06:43');

-- --------------------------------------------------------

--
-- Table structure for table `donations`
--

CREATE TABLE `donations` (
  `donation_id` int(11) NOT NULL,
  `donor_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `subcategory` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `total_quantity` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'Available',
  `donated_at` datetime DEFAULT current_timestamp(),
  `claimed_by_name` varchar(255) DEFAULT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `receiver_id` int(11) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `donor_name` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `donations`
--

INSERT INTO `donations` (`donation_id`, `donor_id`, `item_name`, `category`, `subcategory`, `quantity`, `total_quantity`, `status`, `donated_at`, `claimed_by_name`, `project_name`, `delivered_at`, `requested_by`, `requested_at`, `receiver_id`, `received_at`, `donor_name`, `image_path`, `description`) VALUES
(27, 4, 'Glass', 'Glass', NULL, 2, 2, 'Available', '2025-09-09 19:08:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c05f0c84aec_Trafika Logo.png\",\"assets\\/uploads\\/68c05f0c853ae_EcoWaste UIUX (10).png\",\"assets\\/uploads\\/68c05f0c85940_EcoWaste UIUX (9).png\",\"assets\\/uploads\\/68c05f0c85e67_EcoWaste UIUX (8).png\"]', 'glass heg'),
(28, 4, 'Plastic', 'Plastic', NULL, 7, 7, 'Available', '2025-09-14 08:00:10', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c659eae818a_Plastic-bags-Fillplas.jpg\"]', '7 clean plastics'),
(29, 4, 'Plastic', 'Plastic', NULL, 7, 7, 'Available', '2025-09-14 11:41:59', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c68de7820e9_Plastic-bags-Fillplas.jpg\"]', 'clean plasticss'),
(30, 4, 'Plastic', 'Plastic', NULL, 7, 7, 'Available', '2025-09-14 11:49:42', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c68fb613da8_Plastic-bags-Fillplas.jpg\"]', 'clean plasticss'),
(31, 4, 'Plastic', 'Plastic', NULL, 7, 7, 'Available', '2025-09-14 18:08:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c6e88973986_Plastic-bags-Fillplas.jpg\"]', '777'),
(32, 4, 'Paper', 'Paper', NULL, 7, 7, 'Available', '2025-09-14 18:33:10', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c6ee46a650b_Plastic-bags-Fillplas.jpg\"]', 'seven'),
(33, 4, 'Plastic', 'Plastic', NULL, 7, 7, 'Available', '2025-09-14 20:06:23', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68c7041fe32d1_Plastic-bags-Fillplas.jpg\"]', 'test 7'),
(34, 4, 'Plastic', 'Plastic', NULL, 10, 7, 'Requested', '2025-09-17 18:10:04', NULL, NULL, NULL, 8, '2025-09-19 20:44:03', NULL, NULL, NULL, '[\"assets\\/uploads\\/68cadd5cd57f3_Plastic-bags-Fillplas.jpg\"]', 'sebenn'),
(35, 4, 'Plastic', 'Plastic', NULL, 7, 7, 'Requested', '2025-09-17 18:38:27', NULL, NULL, NULL, 4, '2025-09-19 20:37:45', NULL, NULL, NULL, '[\"assets\\/uploads\\/68cae40374509_Plastic-bags-Fillplas.jpg\"]', 'sevben'),
(41, 4, 'Cans', 'Cans', NULL, 4, 4, 'Requested', '2025-09-19 17:42:40', NULL, NULL, NULL, 4, '2025-09-19 23:42:52', NULL, NULL, NULL, '[\"assets\\/uploads\\/68cd79f0b709b_12_oz_cans_2_2.png\"]', 'fourr'),
(51, 4, 'Plastic Bags (Plastic)', 'Plastic', 'Plastic Bags', 3, 7, 'Available', '2025-10-07 12:28:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68e4eb4429983_Plastic-bags-Fillplas.jpg\"]', 'ssss'),
(81, 8, 'Plastic Bags (Plastic)', 'Plastic', 'Plastic Bags', 2, 7, 'Available', '2025-10-11 09:12:51', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68ea03735e456_Plastic-bags-Fillplas.jpg\"]', 'clean plastic bags'),
(83, 8, 'Newspapers (Paper)', 'Paper', 'Newspapers', 6, 4, 'Available', '2025-10-18 15:17:48', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68f33f1c738ae_limpopomirror-avz-20240306.jpeg\"]', '4 unused newspapers'),
(85, 4, 'Aluminum Cans (Metal)', 'Metal', 'Aluminum Cans', 1, 4, 'Available', '2025-10-26 23:55:39', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/68fe447b14179_12_oz_cans_2_2.png\"]', 'four'),
(93, 12, 'Aluminum Cans (Metal)', 'Metal', 'Aluminum Cans', 3, 3, 'Available', '2025-11-20 11:11:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/691e86c4e3163_12oz-Brite-2.jpg\"]', '3 clean aluminum cans'),
(103, 18, 'Plastic Bags (Plastic)', 'Plastic', 'Plastic Bags', 3, 3, 'Available', '2025-11-25 01:10:55', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[\"assets\\/uploads\\/6924919f575d7_1520092420335.jpg\"]', '3 clean plastic bags');

-- --------------------------------------------------------

--
-- Table structure for table `donation_requests`
--

CREATE TABLE `donation_requests` (
  `request_id` int(11) NOT NULL,
  `donation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `quantity_claim` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `urgency_level` enum('High','Medium','Low') NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivery_start` date DEFAULT NULL,
  `delivery_end` date DEFAULT NULL,
  `delivery_status` enum('Pending','Waiting for Pickup','At Sorting Facility','On the Way','Delivered','Cancelled') DEFAULT 'Pending',
  `project_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','declined') DEFAULT 'pending',
  `pickup_date` date DEFAULT NULL,
  `sorting_facility_date` date DEFAULT NULL,
  `in_transit_date` date DEFAULT NULL,
  `delivered_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `donation_requests`
--

INSERT INTO `donation_requests` (`request_id`, `donation_id`, `user_id`, `quantity_claim`, `project_name`, `urgency_level`, `requested_at`, `delivery_start`, `delivery_end`, `delivery_status`, `project_id`, `status`, `pickup_date`, `sorting_facility_date`, `in_transit_date`, `delivered_date`) VALUES
(20, 81, 4, 2, '', 'Medium', '2025-10-17 17:27:13', NULL, NULL, 'Pending', 14, 'approved', NULL, NULL, NULL, NULL),
(30, 51, 8, 4, '', 'Medium', '2025-10-18 08:12:07', NULL, NULL, 'Pending', 4, 'pending', NULL, NULL, NULL, NULL),
(37, 85, 8, 3, '', 'Medium', '2025-11-12 17:14:50', NULL, NULL, 'Pending', 4, 'pending', NULL, NULL, NULL, NULL),
(38, 51, 18, 3, '', 'Medium', '2025-11-26 18:08:24', '2025-11-29', '2025-11-30', 'Delivered', 21, 'approved', '2025-11-29', '2025-11-30', '2025-12-02', '2025-11-30');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `rating` int(11) NOT NULL,
  `feedback_text` text NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`feedback_id`, `user_id`, `user_name`, `rating`, `feedback_text`, `submitted_at`) VALUES
(1, 4, 'Hanner Kaminari', 4, 'Very Nice!', '2025-09-26 15:20:32'),
(2, 4, 'Hanner Kaminari', 4, 'rawr', '2025-09-26 16:31:34'),
(3, 4, 'Hanner Kaminari', 4, 'rwr', '2025-09-26 16:32:40'),
(4, 4, 'Hanner Kaminari', 4, 'rwre', '2025-09-26 16:33:02'),
(5, 4, 'Hanner Kaminari', 4, 'wrwr', '2025-09-26 16:34:42'),
(6, 4, 'Hanner Kaminari', 4, 'vvv', '2025-09-26 16:48:52'),
(7, 4, 'Hanner Kaminari', 4, 'nicee', '2025-10-03 17:31:55'),
(8, 4, 'Hanner Kaminari', 4, 'wew', '2025-10-03 17:32:59'),
(9, 4, 'Hanner Kaminari', 4, 'weww', '2025-10-03 17:33:05'),
(10, 4, 'Hanner Kaminari', 4, 'weeew', '2025-10-03 17:33:10'),
(11, 4, 'Hanner Kaminari', 4, 'weweww', '2025-10-03 17:33:22'),
(12, 4, 'Hanner Kaminari', 4, 'dsdsds', '2025-10-03 17:35:13'),
(13, 4, 'Hanner Kaminari', 4, 'sdsdd', '2025-10-03 17:36:55');

-- --------------------------------------------------------

--
-- Table structure for table `material_allocations`
--

CREATE TABLE `material_allocations` (
  `allocation_id` int(11) NOT NULL,
  `allocation_group` varchar(64) NOT NULL,
  `project_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `donation_id` int(11) DEFAULT NULL,
  `allocated_quantity` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_donation_requests`
--

CREATE TABLE `material_donation_requests` (
  `request_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `requester_id` int(11) NOT NULL,
  `status` enum('pending','accepted','sent','received','cancelled','expired') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_photos`
--

CREATE TABLE `material_photos` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `photo_path` varchar(255) NOT NULL,
  `photo_type` varchar(16) DEFAULT 'after',
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `material_photos`
--

INSERT INTO `material_photos` (`id`, `material_id`, `project_id`, `photo_path`, `photo_type`, `uploaded_by`, `uploaded_at`) VALUES
(1, 17, 20, 'assets/uploads/materials/mat_6927010357723.jpg', 'after', 4, '2025-11-26 21:30:43');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_types`
--

CREATE TABLE `notification_types` (
  `type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `icon_class` varchar(50) DEFAULT NULL,
  `color_class` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_types`
--

INSERT INTO `notification_types` (`type_id`, `type_name`, `icon_class`, `color_class`) VALUES
(1, 'stage_complete', 'fa-flag-checkered', 'success'),
(2, 'material_added', 'fa-plus-circle', 'info'),
(3, 'material_updated', 'fa-sync', 'info'),
(4, 'project_updated', 'fa-edit', 'primary'),
(5, 'project_shared', 'fa-share', 'info'),
(6, 'material_needed', 'fa-exclamation-circle', 'warning'),
(1, 'stage_complete', 'fa-flag-checkered', 'success'),
(2, 'material_added', 'fa-plus-circle', 'info'),
(3, 'material_updated', 'fa-sync', 'info'),
(4, 'project_updated', 'fa-edit', 'primary'),
(5, 'project_shared', 'fa-share', 'info'),
(6, 'material_needed', 'fa-exclamation-circle', 'warning');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `expires_at`, `created_at`) VALUES
(1, 4, '2f3b954bea5c6fa7b41e1561ef8f45aa7d5708a5c4dd82d5ab02f43942e28b0e', '2025-09-30 05:35:37', '2025-09-30 02:35:37'),
(2, 4, '64c096dd5f625d43e0b7ea0749dae9acd41113acb9b06644eeb158907aef7588', '2025-09-30 05:37:09', '2025-09-30 02:37:09'),
(3, 4, '25c0ba478c4e9263e49632654c32f08c3642ab7fbc256c726c6acd9f7a73c141', '2025-09-30 06:03:36', '2025-09-30 03:03:36'),
(4, 4, '2bcb9b2e8d8379c87108fc0b01f63bc195725cdf864f8bc49ab93e53a48ed671', '2025-09-30 07:30:33', '2025-09-30 04:30:33');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT 'In Progress',
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `user_id`, `project_name`, `description`, `created_at`, `status`, `completed_at`) VALUES
(1, 4, 'Pencil Holder', 'Cute Pencil Holder', '2025-09-01 01:10:22', 'In Progress', NULL),
(2, 4, 'Plastic Holder', 'Nice Plastic Holder', '2025-09-03 15:32:57', 'Completed', NULL),
(4, 8, 'Plastic Vase', '', '2025-09-20 10:11:04', 'In Progress', NULL),
(6, 4, 'Plastic Vase', '', '2025-09-20 10:17:05', 'In Progress', NULL),
(8, 4, 'Plastic Vase', '', '2025-09-20 10:21:24', 'In Progress', NULL),
(10, 4, 'Plastic Vase', '', '2025-09-20 10:21:26', 'In Progress', NULL),
(12, 4, 'Basket', '', '2025-09-20 10:23:40', 'In Progress', NULL),
(14, 4, 'Basket', '', '2025-09-20 10:24:05', 'In Progress', NULL),
(16, 4, 'Fruit Basket', '', '2025-09-20 10:24:36', 'In Progress', NULL),
(19, 4, 'Basket', '', '2025-09-20 10:29:33', 'Completed', '2025-09-20 11:34:12'),
(20, 4, 'Plastic Lights', 'CuteightsfffffffffffffffffddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddXCXCDC', '2025-10-07 19:48:50', 'In Progress', NULL),
(21, 18, 'Plastic Lantern', 'Very unique plastic lantern for christmas', '2025-11-27 02:07:15', 'In Progress', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `project_materials`
--

CREATE TABLE `project_materials` (
  `material_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `material_name` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `status` enum('needed','requested','donated','received','completed') NOT NULL DEFAULT 'needed',
  `unit` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_materials`
--

INSERT INTO `project_materials` (`material_id`, `project_id`, `material_name`, `quantity`, `status`, `unit`, `created_at`) VALUES
(1, 1, 'Cardboard', 2, 'needed', '', '2025-11-29 21:05:49'),
(2, 1, 'Hot Glue', 1, 'needed', '', '2025-11-29 21:05:49'),
(3, 1, 'Scissors', 1, 'needed', '', '2025-11-29 21:05:49'),
(4, 1, 'Stickers', 1, 'needed', '', '2025-11-29 21:05:49'),
(5, 1, 'Colored Papers', 10, 'needed', '', '2025-11-29 21:05:49'),
(6, 2, 'Plastic', 2, 'needed', '', '2025-11-29 21:05:49'),
(7, 2, 'color papers', 5, 'needed', '', '2025-11-29 21:05:49'),
(17, 20, 'Plastic Cups', 12, 'completed', '', '2025-11-29 21:05:49'),
(18, 21, 'Plastic', 12, 'needed', '', '2025-11-29 21:05:49'),
(19, 19, 'Plastic', 4, 'needed', 'pcs', '2025-11-29 21:11:31');

-- --------------------------------------------------------

--
-- Table structure for table `project_photos`
--

CREATE TABLE `project_photos` (
  `photo_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `photo_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_shares`
--

CREATE TABLE `project_shares` (
  `share_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `share_code` varchar(32) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_stages`
--

CREATE TABLE `project_stages` (
  `stage_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `stage_number` int(11) NOT NULL,
  `stage_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_steps`
--

CREATE TABLE `project_steps` (
  `step_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `step_number` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `instructions` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_done` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recycled_ideas`
--

CREATE TABLE `recycled_ideas` (
  `idea_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `posted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rewards`
--

CREATE TABLE `rewards` (
  `reward_id` int(11) NOT NULL,
  `reward_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `cost` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_activities`
--

CREATE TABLE `shared_activities` (
  `id` int(11) NOT NULL,
  `shared_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_comments`
--

CREATE TABLE `shared_comments` (
  `id` int(11) NOT NULL,
  `shared_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_likes`
--

CREATE TABLE `shared_likes` (
  `id` int(11) NOT NULL,
  `shared_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_materials`
--

CREATE TABLE `shared_materials` (
  `id` int(11) NOT NULL,
  `shared_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `quantity` varchar(64) DEFAULT NULL,
  `extra` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_projects`
--

CREATE TABLE `shared_projects` (
  `shared_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `cover_photo` varchar(255) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `privacy` enum('public','private') DEFAULT 'public',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_steps`
--

CREATE TABLE `shared_steps` (
  `id` int(11) NOT NULL,
  `shared_id` int(11) NOT NULL,
  `step_number` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `is_done` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shared_step_photos`
--

CREATE TABLE `shared_step_photos` (
  `id` int(11) NOT NULL,
  `step_id` int(11) NOT NULL,
  `path` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stage_photos`
--

CREATE TABLE `stage_photos` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `stage_number` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stage_templates`
--

CREATE TABLE `stage_templates` (
  `template_id` int(11) NOT NULL,
  `stage_number` int(11) NOT NULL,
  `stage_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `avatar` varchar(500) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `city` varchar(50) NOT NULL,
  `zip_code` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `points` int(11) DEFAULT 0,
  `verification_code` varchar(10) DEFAULT NULL,
  `verified` tinyint(1) DEFAULT 0,
  `remember_token` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `google_id`, `email`, `password_hash`, `first_name`, `middle_name`, `last_name`, `avatar`, `contact_number`, `address`, `city`, `zip_code`, `created_at`, `points`, `verification_code`, `verified`, `remember_token`, `token_expiry`, `is_verified`, `verification_token`) VALUES
(1, NULL, 'qwert@gmail.com', '$2y$10$9I5mxH9063gc550gzzyoZO6pGlYRkhPJqrkRPqB0ZEJHnskKzvUC6', 'qwer', 't', 'y', NULL, '09876543210', 'qweqwe', 'qweqwecity', '6000', '2025-08-17 12:02:45', 0, NULL, 0, NULL, NULL, 0, NULL),
(2, NULL, 'poiuytre@gmail.com', '$2y$10$A9hcXYjCflZkfbrW406PwekBPPc/MWcyELzljNgLg0sKZ3mZjFOua', 'poi', 'uy', 'tre', NULL, '09876543211', 'qwe', 'qwe city', '6000', '2025-08-17 12:04:06', 0, NULL, 0, NULL, NULL, 0, NULL),
(3, NULL, 'qwerty1@gmail.com', '$2y$10$NbdppA9ez90BY7.JPy56MuuPKrdAiMPoUvrERL93PU.tuMnqaCUyW', 'qwe', 'rt', 'y', NULL, '12345678910', 'harju', 'harju city', '6000', '2025-08-17 14:14:02', 0, NULL, 0, NULL, NULL, 0, NULL),
(4, NULL, 'hanner707@gmail.com', '$2y$10$F9DjB8esLZUJPuJu/MQSjOSuv4oh/LcJF4ekAHnucVhDfbsK.Et9S', 'Hanner', 'Aoi', 'Kaminari', NULL, '09651634871', 'Sitio Japan', 'Osaka', '707', '2025-08-21 09:22:46', 810, NULL, 0, NULL, NULL, 0, NULL),
(7, NULL, 'codm@gmail.com', '$2y$10$OYMA8TVpxb/qhVG/x.6FzO2QA7TgZnUC1s.Zz5RbJVXUDDlaF879m', 'Call', 'Of Duty', 'Mobile', NULL, '911', 'Shipment', 'Airport', '707', '2025-09-08 07:33:57', 0, NULL, 0, NULL, NULL, 0, NULL),
(8, NULL, 'kenshi@gmail.com', '$2y$10$eHLM/BqI93YEX3tzxPM7suluiQIE9SbZyh0scX9ESeBaNbAozVsjW', 'Princess Kenshi', 'Pizarras', 'Quitor', NULL, '09876543210', 'Sitio San Vicente, Lahug', 'Cebu City', '6000', '2025-09-18 05:58:58', 40, NULL, 0, NULL, NULL, 0, NULL),
(12, '115113529960597644353', 'princesskenshiquitor73@gmail.com', '', 'Haruka', NULL, 'Kirisaki', 'https://lh3.googleusercontent.com/a/ACg8ocI2WpMMDtQgI0J_d9r65N9q4JDxSPvK5bK8zTTgxv8dDd3hmZ6lRA=s96-c', '', '', '', '', '2025-11-20 03:10:22', 20, NULL, 0, '83f1013355a593136a7749a8f3477341189776362365cf7efb4e6bc458092e6c', '2025-12-20 11:10:22', 0, NULL),
(18, '117191164987494583420', 'princesskenshi73@gmail.com', '', 'Princess', NULL, 'Kenshi P. Quitor', 'https://lh3.googleusercontent.com/a/ACg8ocIfS8m9yhq0xNduBtx17FlFzBgfG-o1SzKuJlQGCSXIlF20vJUe=s96-c', '', '', '', '', '2025-11-24 17:10:11', 40, NULL, 0, '9911d6adb9a7e540d86b02a432d6b9a73ec18e587cbcea211fbcd1c5858afa9b', '2025-12-30 01:29:29', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_activities`
--

CREATE TABLE `user_activities` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` enum('recycling','donation','badge','project') NOT NULL,
  `description` text NOT NULL,
  `points_earned` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `item_name` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `claimed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_activities`
--

INSERT INTO `user_activities` (`id`, `user_id`, `activity_type`, `description`, `points_earned`, `created_at`, `item_name`, `quantity`, `claimed`) VALUES
(1, 4, 'recycling', 'Recycled plastic bottles', 15, '2025-08-30 16:59:18', NULL, 1, 0),
(2, 4, 'donation', 'Donated old clothes', 20, '2025-08-30 16:59:18', NULL, 1, 0),
(3, 4, 'badge', 'Earned Eco Beginner badge', 10, '2025-08-30 16:59:18', NULL, 1, 0),
(4, 4, 'project', 'Completed Community Cleanup project', 25, '2025-08-30 16:59:18', NULL, 1, 0),
(9, 8, 'recycling', 'Recycled plastic bottles', 15, '2025-09-18 06:11:05', NULL, 1, 0),
(10, 8, 'donation', 'Donated old clothes', 20, '2025-09-18 06:11:05', NULL, 1, 0),
(11, 8, 'badge', 'Earned Eco Beginner badge', 10, '2025-09-18 06:11:05', NULL, 1, 0),
(12, 8, 'project', 'Completed Community Cleanup project', 25, '2025-09-18 06:11:05', NULL, 1, 0),
(13, 11, 'recycling', 'Recycled plastic bottles', 15, '2025-11-19 19:11:57', NULL, 1, 0),
(14, 11, 'donation', 'Donated old clothes', 20, '2025-11-19 19:11:57', NULL, 1, 0),
(15, 11, 'badge', 'Earned Eco Beginner badge', 10, '2025-11-19 19:11:57', NULL, 1, 0),
(16, 11, 'project', 'Completed Community Cleanup project', 25, '2025-11-19 19:11:57', NULL, 1, 0),
(17, 12, 'recycling', 'Recycled plastic bottles', 15, '2025-11-20 03:14:18', NULL, 1, 0),
(18, 12, 'donation', 'Donated old clothes', 20, '2025-11-20 03:14:18', NULL, 1, 0),
(19, 12, 'badge', 'Earned Eco Beginner badge', 10, '2025-11-20 03:14:18', NULL, 1, 0),
(20, 12, 'project', 'Completed Community Cleanup project', 25, '2025-11-20 03:14:18', NULL, 1, 0),
(21, 13, 'recycling', 'Recycled plastic bottles', 15, '2025-11-24 07:10:51', NULL, 1, 0),
(22, 13, 'donation', 'Donated old clothes', 20, '2025-11-24 07:10:51', NULL, 1, 0),
(23, 13, 'badge', 'Earned Eco Beginner badge', 10, '2025-11-24 07:10:51', NULL, 1, 0),
(24, 13, 'project', 'Completed Community Cleanup project', 25, '2025-11-24 07:10:51', NULL, 1, 0),
(25, 13, 'donation', 'You donated 7 Plastic Bags (Plastic)', 35, '2025-11-24 07:28:20', NULL, 1, 0),
(27, 14, 'donation', 'You donated 3 Plastic Bags (Plastic)', 15, '2025-11-24 11:44:22', NULL, 1, 0),
(28, 15, 'donation', 'You donated 3 Plastic Bags (Plastic)', 15, '2025-11-24 12:36:51', NULL, 1, 0),
(29, 16, 'donation', 'You donated 3 Plastic Bags (Plastic)', 15, '2025-11-24 14:46:23', NULL, 1, 0),
(30, 16, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-24 14:46:29', NULL, 1, 0),
(31, 16, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-24 14:46:29', NULL, 1, 0),
(32, 16, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-24 14:46:29', NULL, 1, 0),
(33, 16, 'donation', 'You donated 3 Plastic Bags (Plastic)', 15, '2025-11-24 15:48:16', NULL, 1, 0),
(34, 16, 'badge', 'Earned badge: EcoWaste Rookie', 50, '2025-11-24 16:12:30', NULL, 1, 0),
(35, 17, 'donation', 'You donated 3 Plastic Bags (Plastic)', 15, '2025-11-24 16:33:56', NULL, 1, 0),
(36, 17, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-24 16:34:02', NULL, 1, 0),
(37, 17, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-24 16:34:02', NULL, 1, 0),
(38, 17, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-24 16:34:02', NULL, 1, 0),
(39, 18, 'donation', 'You donated 3 Plastic Bags (Plastic)', 15, '2025-11-24 17:10:55', NULL, 1, 0),
(40, 18, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-24 17:11:01', NULL, 1, 0),
(41, 18, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-24 17:11:01', NULL, 1, 0),
(42, 18, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-24 17:11:01', NULL, 1, 0),
(43, 4, 'badge', 'Earned badge: Donation Hero', 75, '2025-11-24 17:49:21', NULL, 1, 0),
(44, 4, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-24 17:49:21', NULL, 1, 0),
(45, 4, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-24 17:49:21', NULL, 1, 0),
(46, 4, 'badge', 'Earned badge: Eco Star', 15, '2025-11-24 17:49:21', NULL, 1, 0),
(47, 4, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-24 17:49:21', NULL, 1, 0),
(48, 4, 'badge', 'Earned badge: Donation Champion', 225, '2025-11-24 17:49:21', NULL, 1, 0),
(49, 4, 'badge', 'Earned badge: Recycling Starter', 10, '2025-11-24 17:49:21', NULL, 1, 0),
(50, 4, 'badge', 'Earned badge: EcoWaste Rookie', 50, '2025-11-24 18:05:47', NULL, 1, 0),
(51, 4, 'badge', 'Earned badge: EcoWaste Master', 100, '2025-11-24 18:05:47', NULL, 1, 0),
(52, 4, 'badge', 'Earned badge: EcoWaste Warrior', 200, '2025-11-24 18:05:47', NULL, 1, 0),
(53, 4, 'badge', 'Earned badge: EcoWaste Legend', 500, '2025-11-24 18:05:47', NULL, 1, 0),
(54, 8, 'badge', 'Earned badge: Donation Hero', 75, '2025-11-24 18:09:16', NULL, 1, 0),
(55, 8, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-24 18:09:16', NULL, 1, 0),
(56, 8, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-24 18:09:16', NULL, 1, 0),
(57, 8, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-24 18:09:16', NULL, 1, 0),
(58, 18, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-26 08:54:51', NULL, 1, 0),
(59, 18, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-26 08:54:51', NULL, 1, 0),
(60, 18, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-26 08:54:51', NULL, 1, 0),
(61, 18, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-26 09:51:36', NULL, 1, 0),
(62, 18, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-26 09:51:36', NULL, 1, 0),
(63, 18, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-26 09:51:36', NULL, 1, 0),
(64, 18, 'badge', 'Earned badge: EcoWaste Beginner', 10, '2025-11-26 10:03:57', NULL, 1, 0),
(65, 18, 'badge', 'Earned badge: Rising Donor', 10, '2025-11-26 10:03:57', NULL, 1, 0),
(66, 18, 'badge', 'Earned badge: Donation Starter', 20, '2025-11-26 10:03:57', NULL, 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_badges`
--

CREATE TABLE `user_badges` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `badge_id` int(11) NOT NULL,
  `earned_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_badges`
--

INSERT INTO `user_badges` (`id`, `user_id`, `badge_id`, `earned_date`) VALUES
(1, 4, 1, '2025-09-14 09:52:58'),
(2, 8, 1, '2025-09-18 06:11:05'),
(3, 12, 1, '2025-11-20 03:14:18'),
(4, 11, 1, '2025-11-20 18:39:23'),
(5, 13, 1, '2025-11-24 07:28:26'),
(6, 13, 6, '2025-11-24 10:46:41'),
(7, 13, 7, '2025-11-24 10:46:41'),
(8, 13, 13, '2025-11-24 10:46:41'),
(9, 13, 8, '2025-11-24 10:46:41'),
(10, 13, 9, '2025-11-24 10:46:41'),
(15, 14, 6, '2025-11-24 11:44:36'),
(16, 14, 7, '2025-11-24 11:44:36'),
(17, 14, 13, '2025-11-24 11:44:36'),
(18, 14, 8, '2025-11-24 11:44:36'),
(19, 14, 9, '2025-11-24 11:44:36'),
(20, 14, 4, '2025-11-24 11:51:54'),
(21, 14, 5, '2025-11-24 11:51:54'),
(22, 14, 11, '2025-11-24 11:51:54'),
(23, 15, 7, '2025-11-24 12:36:58'),
(24, 15, 9, '2025-11-24 12:56:18'),
(25, 15, 6, '2025-11-24 13:30:56'),
(26, 16, 6, '2025-11-24 14:46:29'),
(27, 16, 7, '2025-11-24 14:46:29'),
(28, 16, 9, '2025-11-24 14:46:29'),
(29, 16, 19, '2025-11-24 16:12:30'),
(30, 17, 6, '2025-11-24 16:34:02'),
(31, 17, 7, '2025-11-24 16:34:02'),
(32, 17, 9, '2025-11-24 16:34:02'),
(36, 4, 3, '2025-11-24 17:49:21'),
(37, 4, 6, '2025-11-24 17:49:21'),
(38, 4, 7, '2025-11-24 17:49:21'),
(39, 4, 8, '2025-11-24 17:49:21'),
(40, 4, 9, '2025-11-24 17:49:21'),
(41, 4, 10, '2025-11-24 17:49:21'),
(42, 4, 13, '2025-11-24 17:49:21'),
(43, 4, 19, '2025-11-24 18:05:47'),
(44, 4, 20, '2025-11-24 18:05:47'),
(45, 4, 21, '2025-11-24 18:05:47'),
(46, 4, 22, '2025-11-24 18:05:47'),
(47, 8, 3, '2025-11-24 18:09:16'),
(48, 8, 6, '2025-11-24 18:09:16'),
(49, 8, 7, '2025-11-24 18:09:16'),
(50, 8, 9, '2025-11-24 18:09:16'),
(57, 18, 6, '2025-11-26 10:03:57'),
(58, 18, 7, '2025-11-26 10:03:57'),
(59, 18, 9, '2025-11-26 10:03:57');

-- --------------------------------------------------------

--
-- Table structure for table `user_stats`
--

CREATE TABLE `user_stats` (
  `stat_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `projects_completed` int(11) DEFAULT 0,
  `achievements_earned` int(11) DEFAULT 0,
  `badges_earned` int(11) DEFAULT 0,
  `items_donated` int(11) DEFAULT 0,
  `items_recycled` int(11) DEFAULT 0,
  `plastic_recycled` int(11) DEFAULT 0,
  `paper_recycled` int(11) DEFAULT 0,
  `glass_recycled` int(11) DEFAULT 0,
  `metal_recycled` int(11) DEFAULT 0,
  `points` int(11) DEFAULT 0,
  `projects_created` int(11) DEFAULT 0,
  `total_points` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_stats`
--

INSERT INTO `user_stats` (`stat_id`, `user_id`, `projects_completed`, `achievements_earned`, `badges_earned`, `items_donated`, `items_recycled`, `plastic_recycled`, `paper_recycled`, `glass_recycled`, `metal_recycled`, `points`, `projects_created`, `total_points`) VALUES
(1, 4, 1, 9, 12, 862, 1, 0, 0, 0, 0, 0, 0, 810),
(3, 8, 0, 1, 5, 123, 0, 0, 0, 0, 0, 0, 0, 40),
(6, 12, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0),
(18, 18, 0, 2, 3, 3, 0, 0, 0, 0, 0, 0, 1, 40);

-- --------------------------------------------------------

--
-- Table structure for table `user_tasks`
--

CREATE TABLE `user_tasks` (
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `reward` varchar(255) DEFAULT NULL,
  `progress` varchar(50) DEFAULT NULL,
  `current_value` int(11) DEFAULT 0,
  `target_value` int(11) DEFAULT 1,
  `action_type` varchar(50) DEFAULT NULL,
  `task_type` varchar(50) NOT NULL,
  `reward_type` enum('points','badge') DEFAULT 'points',
  `reward_value` varchar(255) DEFAULT NULL,
  `reward_claimed` tinyint(4) DEFAULT 0,
  `unlocked` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_tasks`
--

INSERT INTO `user_tasks` (`task_id`, `user_id`, `title`, `description`, `status`, `reward`, `progress`, `current_value`, `target_value`, `action_type`, `task_type`, `reward_type`, `reward_value`, `reward_claimed`, `unlocked`) VALUES
(17, 4, 'Rising Donor', 'Complete your first donation', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'donations', '', 'points', '20', 1, 1),
(18, 4, 'Helpful Friend', 'Complete 10 donations', 'Completed', '50 EcoPoints', '10/10', 10, 10, 'donations', '', 'points', '50', 1, 1),
(19, 4, 'Care Giver', 'Complete 15 donations', 'In Progress', '100 EcoPoints', '1/15', 1, 15, 'donations', '', 'points', '100', 1, 1),
(20, 4, 'Generous Giver', 'Complete 20 donations', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'donations', '', 'points', '150', 1, 1),
(21, 4, 'Community Helper', 'Complete 25 donations', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'donations', '', 'points', '200', 1, 1),
(22, 4, 'Charity Champion', 'Complete 30 donations', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'donations', '', 'points', '250', 1, 1),
(23, 4, 'Eco Beginner', 'Start your first recycling project', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'projects_created', '', 'points', '20', 1, 1),
(24, 4, 'Eco Builder', 'Create 10 recycling projects', 'In Progress', '50 EcoPoints', '9/10', 9, 10, 'projects_created', '', 'points', '50', 0, 1),
(25, 4, 'Nature Keeper', 'Create 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_created', '', 'points', '100', 0, 0),
(26, 4, 'Conservation Expert', 'Create 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_created', '', 'points', '150', 0, 0),
(27, 4, 'Zero Waste Hero', 'Create 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_created', '', 'points', '200', 0, 0),
(28, 4, 'Earth Saver', 'Create 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_created', '', 'points', '250', 0, 0),
(29, 4, 'Eco Star', 'Complete a recycling project', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'projects_completed', '', 'points', '20', 1, 1),
(30, 4, 'Eco Warrior', 'Complete 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_completed', '', 'points', '50', 0, 1),
(31, 4, 'Eco Elite', 'Complete 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_completed', '', 'points', '100', 0, 0),
(32, 4, 'Eco Pro', 'Complete 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_completed', '', 'points', '150', 0, 0),
(33, 4, 'Eco Master', 'Complete 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_completed', '', 'points', '200', 0, 0),
(34, 4, 'Eco Legend', 'Complete 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_completed', '', 'points', '250', 0, 0),
(144, 8, 'Rising Donor', 'Complete your first donation', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'donations', '', 'points', '20', 1, 1),
(145, 8, 'Helpful Friend', 'Complete 10 donations', 'In Progress', '50 EcoPoints', '1/10', 1, 10, 'donations', '', 'points', '50', 0, 1),
(146, 8, 'Care Giver', 'Complete 15 donations', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'donations', '', 'points', '100', 0, 0),
(147, 8, 'Generous Giver', 'Complete 20 donations', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'donations', '', 'points', '150', 0, 0),
(148, 8, 'Community Helper', 'Complete 25 donations', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'donations', '', 'points', '200', 0, 0),
(149, 8, 'Charity Champion', 'Complete 30 donations', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'donations', '', 'points', '250', 0, 0),
(150, 8, 'Eco Beginner', 'Start your first recycling project', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'projects_created', '', 'points', '20', 1, 1),
(151, 8, 'Eco Builder', 'Create 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_created', '', 'points', '50', 0, 1),
(152, 8, 'Nature Keeper', 'Create 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_created', '', 'points', '100', 0, 0),
(153, 8, 'Conservation Expert', 'Create 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_created', '', 'points', '150', 0, 0),
(154, 8, 'Zero Waste Hero', 'Create 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_created', '', 'points', '200', 0, 0),
(155, 8, 'Earth Saver', 'Create 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_created', '', 'points', '250', 0, 0),
(156, 8, 'Eco Star', 'Complete a recycling project', 'In Progress', '20 EcoPoints', '0/1', 0, 1, 'projects_completed', '', 'points', '20', 0, 1),
(157, 8, 'Eco Warrior', 'Complete 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_completed', '', 'points', '50', 0, 0),
(158, 8, 'Eco Elite', 'Complete 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_completed', '', 'points', '100', 0, 0),
(159, 8, 'Eco Pro', 'Complete 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_completed', '', 'points', '150', 0, 0),
(160, 8, 'Eco Master', 'Complete 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_completed', '', 'points', '200', 0, 0),
(161, 8, 'Eco Legend', 'Complete 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_completed', '', 'points', '250', 0, 0),
(180, 12, 'Rising Donor', 'Complete your first donation', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'donations', '', 'points', '20', 1, 1),
(181, 12, 'Helpful Friend', 'Complete 10 donations', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'donations', '', 'points', '50', 0, 1),
(182, 12, 'Care Giver', 'Complete 15 donations', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'donations', '', 'points', '100', 0, 0),
(183, 12, 'Generous Giver', 'Complete 20 donations', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'donations', '', 'points', '150', 0, 0),
(184, 12, 'Community Helper', 'Complete 25 donations', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'donations', '', 'points', '200', 0, 0),
(185, 12, 'Charity Champion', 'Complete 30 donations', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'donations', '', 'points', '250', 0, 0),
(186, 12, 'Eco Beginner', 'Start your first recycling project', 'In Progress', '20 EcoPoints', '0/1', 0, 1, 'projects_created', '', 'points', '20', 0, 1),
(187, 12, 'Eco Builder', 'Create 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_created', '', 'points', '50', 0, 0),
(188, 12, 'Nature Keeper', 'Create 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_created', '', 'points', '100', 0, 0),
(189, 12, 'Conservation Expert', 'Create 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_created', '', 'points', '150', 0, 0),
(190, 12, 'Zero Waste Hero', 'Create 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_created', '', 'points', '200', 0, 0),
(191, 12, 'Earth Saver', 'Create 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_created', '', 'points', '250', 0, 0),
(192, 12, 'Eco Star', 'Complete a recycling project', 'In Progress', '20 EcoPoints', '0/1', 0, 1, 'projects_completed', '', 'points', '20', 0, 1),
(193, 12, 'Eco Warrior', 'Complete 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_completed', '', 'points', '50', 0, 0),
(194, 12, 'Eco Elite', 'Complete 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_completed', '', 'points', '100', 0, 0),
(195, 12, 'Eco Pro', 'Complete 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_completed', '', 'points', '150', 0, 0),
(196, 12, 'Eco Master', 'Complete 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_completed', '', 'points', '200', 0, 0),
(197, 12, 'Eco Legend', 'Complete 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_completed', '', 'points', '250', 0, 0),
(288, 18, 'Rising Donor', 'Complete your first donation', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'donations', '', 'points', '20', 1, 1),
(289, 18, 'Helpful Friend', 'Complete 10 donations', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'donations', '', 'points', '50', 0, 1),
(290, 18, 'Care Giver', 'Complete 15 donations', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'donations', '', 'points', '100', 0, 0),
(291, 18, 'Generous Giver', 'Complete 20 donations', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'donations', '', 'points', '150', 0, 0),
(292, 18, 'Community Helper', 'Complete 25 donations', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'donations', '', 'points', '200', 0, 0),
(293, 18, 'Charity Champion', 'Complete 30 donations', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'donations', '', 'points', '250', 0, 0),
(294, 18, 'Eco Beginner', 'Start your first recycling project', 'Completed', '20 EcoPoints', '1/1', 1, 1, 'projects_created', '', 'points', '20', 1, 1),
(295, 18, 'Eco Builder', 'Create 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_created', '', 'points', '50', 0, 1),
(296, 18, 'Nature Keeper', 'Create 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_created', '', 'points', '100', 0, 0),
(297, 18, 'Conservation Expert', 'Create 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_created', '', 'points', '150', 0, 0),
(298, 18, 'Zero Waste Hero', 'Create 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_created', '', 'points', '200', 0, 0),
(299, 18, 'Earth Saver', 'Create 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_created', '', 'points', '250', 0, 0),
(300, 18, 'Eco Star', 'Complete a recycling project', 'In Progress', '20 EcoPoints', '0/1', 0, 1, 'projects_completed', '', 'points', '20', 0, 1),
(301, 18, 'Eco Warrior', 'Complete 10 recycling projects', 'In Progress', '50 EcoPoints', '0/10', 0, 10, 'projects_completed', '', 'points', '50', 0, 0),
(302, 18, 'Eco Elite', 'Complete 15 recycling projects', 'In Progress', '100 EcoPoints', '0/15', 0, 15, 'projects_completed', '', 'points', '100', 0, 0),
(303, 18, 'Eco Pro', 'Complete 20 recycling projects', 'In Progress', '150 EcoPoints', '0/20', 0, 20, 'projects_completed', '', 'points', '150', 0, 0),
(304, 18, 'Eco Master', 'Complete 25 recycling projects', 'In Progress', '200 EcoPoints', '0/25', 0, 25, 'projects_completed', '', 'points', '200', 0, 0),
(305, 18, 'Eco Legend', 'Complete 30 recycling projects', 'In Progress', '250 EcoPoints', '0/30', 0, 30, 'projects_completed', '', 'points', '250', 0, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `badges`
--
ALTER TABLE `badges`
  ADD PRIMARY KEY (`badge_id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `donation_id` (`donation_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `donations`
--
ALTER TABLE `donations`
  ADD PRIMARY KEY (`donation_id`),
  ADD KEY `donor_id` (`donor_id`);

--
-- Indexes for table `donation_requests`
--
ALTER TABLE `donation_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `donation_id` (`donation_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `request_id` (`request_id`,`donation_id`,`user_id`,`quantity_claim`,`project_name`,`urgency_level`,`requested_at`,`delivery_start`,`delivery_end`,`delivery_status`,`project_id`,`status`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`feedback_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `material_allocations`
--
ALTER TABLE `material_allocations`
  ADD PRIMARY KEY (`allocation_id`);

--
-- Indexes for table `material_photos`
--
ALTER TABLE `material_photos`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `project_materials`
--
ALTER TABLE `project_materials`
  ADD PRIMARY KEY (`material_id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `recycled_ideas`
--
ALTER TABLE `recycled_ideas`
  ADD PRIMARY KEY (`idea_id`);

--
-- Indexes for table `shared_activities`
--
ALTER TABLE `shared_activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `shared_materials`
--
ALTER TABLE `shared_materials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_activities`
--
ALTER TABLE `user_activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_badges`
--
ALTER TABLE `user_badges`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_stats`
--
ALTER TABLE `user_stats`
  ADD PRIMARY KEY (`stat_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_tasks`
--
ALTER TABLE `user_tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `badges`
--
ALTER TABLE `badges`
  MODIFY `badge_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `donations`
--
ALTER TABLE `donations`
  MODIFY `donation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=104;

--
-- AUTO_INCREMENT for table `donation_requests`
--
ALTER TABLE `donation_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `material_allocations`
--
ALTER TABLE `material_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_photos`
--
ALTER TABLE `material_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `project_materials`
--
ALTER TABLE `project_materials`
  MODIFY `material_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `recycled_ideas`
--
ALTER TABLE `recycled_ideas`
  MODIFY `idea_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shared_activities`
--
ALTER TABLE `shared_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shared_materials`
--
ALTER TABLE `shared_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `user_activities`
--
ALTER TABLE `user_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `user_badges`
--
ALTER TABLE `user_badges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `user_stats`
--
ALTER TABLE `user_stats`
  MODIFY `stat_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `user_tasks`
--
ALTER TABLE `user_tasks`
  MODIFY `task_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=306;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`donation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `donations`
--
ALTER TABLE `donations`
  ADD CONSTRAINT `donations_ibfk_1` FOREIGN KEY (`donor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `donation_requests`
--
ALTER TABLE `donation_requests`
  ADD CONSTRAINT `donation_requests_ibfk_1` FOREIGN KEY (`donation_id`) REFERENCES `donations` (`donation_id`),
  ADD CONSTRAINT `donation_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `project_materials`
--
ALTER TABLE `project_materials`
  ADD CONSTRAINT `project_materials_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`);

--
-- Constraints for table `user_stats`
--
ALTER TABLE `user_stats`
  ADD CONSTRAINT `user_stats_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `user_tasks`
--
ALTER TABLE `user_tasks`
  ADD CONSTRAINT `user_tasks_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
