-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 13, 2026 at 07:50 AM
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
-- Database: `tutor`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `allow_late_submission` tinyint(1) DEFAULT 1,
  `total_points` int(11) DEFAULT 100,
  `material_url` text DEFAULT NULL,
  `file_name` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` varchar(500) DEFAULT NULL,
  `file_type` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `is_url` tinyint(1) DEFAULT 0,
  `late_cutoff_type` varchar(50) DEFAULT 'until_due',
  `late_days` int(11) DEFAULT NULL,
  `late_cutoff_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `booking_id`, `tutor_id`, `student_id`, `title`, `description`, `due_date`, `allow_late_submission`, `total_points`, `material_url`, `file_name`, `file_path`, `file_size`, `file_type`, `created_at`, `updated_at`, `is_url`, `late_cutoff_type`, `late_days`, `late_cutoff_date`) VALUES
(1, 37, 3, 4, 'Self Introduction', 'Record a short video or voice note introducing yourself in Mandarin (30–60 seconds).\r\n\r\nInclude:\r\n\r\nYour name\r\nYour age\r\nWhere you are from\r\nOne hobby you enjoy', '2026-05-30 12:00:00', 0, 100, NULL, NULL, NULL, NULL, NULL, '2026-05-24 14:42:49', '2026-06-04 16:54:17', 0, 'no_limit', 7, NULL),
(11, 55, 3, 2, 'HOMEWORK 1', 'PLEASE FINISH IT BEFORE CLASS', '2026-06-04 11:50:00', 1, 100, NULL, 'japanese grammar.pdf', '1780567847_2cab181d13ed3ef6.pdf', '1252504', 'application/pdf', '2026-06-04 03:44:20', '2026-06-04 18:10:47', 0, 'no_limit', 7, NULL),
(15, 48, 3, 2, 'HOMEWORK 2', 'Please finish it before the deadline', '2026-06-11 18:03:00', 0, 100, NULL, 'P75261 GCSE Japanese 1JA0 2F CAND RPC.pdf|japanese grammar.pdf', '1780565103_d5d2554ba14654d6.pdf|1780565103_003040252503eb86.pdf', '1510749', '0', '2026-06-04 09:25:03', '2026-06-04 18:03:59', 0, 'days_after', 7, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `submission_text` text DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_submissions`
--

INSERT INTO `assignment_submissions` (`id`, `assignment_id`, `student_id`, `tutor_id`, `booking_id`, `submission_text`, `file_name`, `file_path`, `file_type`, `file_size`, `status`, `submitted_at`, `reviewed_at`, `feedback`, `grade`, `graded_at`) VALUES
(1, 1, 4, 3, 37, NULL, 'SELF-INTRODUCTION VIDEO _ 30 SECONDS.mp4', 'submission_4_1779900759_f46b54b0.mp4', 'video/mp4', 7443228, 'submitted', '2026-05-27 16:52:39', NULL, 'Good Job!', '88', '2026-05-28 01:56:09'),
(3, 11, 2, 3, 55, NULL, 'P75261 GCSE Japanese 1JA0 2F CAND RPC.pdf', '1780545131_5d50c9d452b75e09.pdf', 'application/pdf', 258245, 'submitted', '2026-06-04 03:52:11', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_proofs`
--

CREATE TABLE `attendance_proofs` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` enum('tutor','student') DEFAULT NULL,
  `proof_type` enum('screenshot','photo','qr_scan') DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  `verified` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `language` varchar(50) NOT NULL,
  `learning_mode` enum('online','face_to_face') NOT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `status` enum('pending','accepted','confirmed','rescheduled','completed','cancelled','disputed') DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `auto_completed` tinyint(1) DEFAULT 0,
  `cancelled_by` enum('student','tutor','admin') DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `meeting_location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `focus` varchar(255) DEFAULT NULL,
  `meeting_link` varchar(500) DEFAULT NULL,
  `link_provided_at` datetime DEFAULT NULL,
  `link_reminder_sent` tinyint(1) DEFAULT 0,
  `proficiency_level` varchar(20) DEFAULT 'beginner',
  `reminder_sent` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `student_id`, `tutor_id`, `language`, `learning_mode`, `booking_date`, `booking_time`, `total_amount`, `status`, `completed_at`, `auto_completed`, `cancelled_by`, `cancel_reason`, `meeting_location`, `created_at`, `notes`, `focus`, `meeting_link`, `link_provided_at`, `link_reminder_sent`, `proficiency_level`, `reminder_sent`) VALUES
(1, 2, 5, 'Japanese', 'online', '2026-06-13', '13:00:00', 45.00, 'confirmed', '2026-05-29 13:39:46', 1, NULL, NULL, NULL, '2026-05-09 07:25:23', '', 'Writing', 'https://meet.google.com/xvk-aohf-sei', '2026-06-13 12:51:49', 1, 'beginner', 1),
(2, 2, 10, 'English', 'online', '2026-05-21', '01:00:00', 47.00, 'cancelled', '2026-06-06 12:00:19', 1, 'admin', 'Refunded due to dispute resolution', NULL, '2026-05-09 07:25:23', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(3, 2, 3, 'Mandarin', 'face_to_face', '2026-04-10', '09:00:00', 50.00, 'cancelled', NULL, 0, 'student', NULL, 'KLCC', '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner', 0),
(4, 2, 6, 'English', 'online', '2026-04-01', '11:00:00', 50.00, 'cancelled', NULL, 0, 'tutor', 'Not free at that moment', NULL, '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner', 0),
(5, 2, 8, 'Korean', 'online', '2026-03-15', '15:00:00', 46.00, 'cancelled', NULL, 0, 'student', NULL, NULL, '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner', 0),
(7, 4, 9, 'Malay', 'face_to_face', '2026-04-20', '13:00:00', 40.00, 'completed', NULL, 0, NULL, NULL, 'Mid Valley', '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner', 0),
(8, 2, 3, 'English', 'online', '2026-05-12', '12:00:00', 50.00, 'cancelled', NULL, 0, 'admin', NULL, NULL, '2026-05-09 13:09:59', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(9, 2, 3, 'English', 'online', '2026-05-13', '12:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-09 13:09:59', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(10, 2, 3, 'English', 'online', '2026-05-12', '11:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(11, 2, 3, 'English', 'online', '2026-05-12', '13:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(12, 2, 3, 'English', 'online', '2026-05-12', '17:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(13, 2, 3, 'English', 'online', '2026-05-12', '12:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(14, 2, 9, 'Malay', 'face_to_face', '2026-05-27', '10:00:00', 40.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', 'Starbucks, Jalan Borneo, Menggatal, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88813, Malaysia', '2026-05-11 04:49:50', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(15, 2, 3, 'Mandarin', 'online', '2026-05-12', '16:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(16, 2, 3, 'Mandarin', 'online', '2026-05-12', '18:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(17, 2, 3, 'Mandarin', 'online', '2026-05-27', '14:00:00', 50.00, 'cancelled', NULL, 0, '', 'Payment not received before deadline', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(18, 2, 3, 'Mandarin', 'online', '2026-05-28', '17:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(19, 2, 8, 'Korean', 'online', '2026-06-06', '12:00:00', 46.00, 'completed', '2026-06-07 15:37:01', 1, NULL, NULL, NULL, '2026-05-13 16:10:03', '', 'Speaking, Listening', NULL, NULL, 1, 'beginner', 1),
(20, 2, 8, 'Korean', 'online', '2026-05-30', '16:00:00', 46.00, 'completed', '2026-06-01 17:06:45', 1, NULL, NULL, NULL, '2026-05-13 16:10:03', '', 'Speaking, Listening', NULL, NULL, 1, 'beginner', 1),
(21, 2, 9, 'Malay', 'face_to_face', '2026-05-14', '09:00:00', 40.00, 'completed', '2026-05-20 03:46:08', 1, NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(22, 2, 9, 'Malay', 'face_to_face', '2026-05-29', '09:00:00', 40.00, 'completed', '2026-05-30 13:57:20', 1, NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(23, 2, 9, 'Malay', 'face_to_face', '2026-05-20', '09:00:00', 40.00, 'cancelled', NULL, 0, NULL, 'Payment not received before session time', 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(24, 2, 10, 'English', 'online', '2026-05-29', '12:00:00', 47.00, 'completed', '2026-06-03 12:00:14', 1, NULL, 'Payment not received before session time', NULL, '2026-05-17 17:20:29', '', 'Listening, Reading', NULL, NULL, 0, 'beginner', 0),
(25, 2, 7, 'Mandarin', 'online', '2026-05-20', '18:00:00', 48.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-17 20:24:28', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(26, 2, 5, 'Japanese', 'online', '2026-05-20', '11:00:00', 45.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-18 04:02:38', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(27, 2, 5, 'Japanese', 'online', '2026-05-29', '16:00:00', 45.00, 'cancelled', NULL, 0, 'student', 'Change of plans', NULL, '2026-05-19 12:02:46', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(28, 2, 3, 'Mandarin', 'face_to_face', '2026-05-21', '12:00:00', 50.00, 'completed', '2026-05-24 20:55:16', 0, NULL, NULL, 'Starbucks, Jalan 13/6, Seksyen 13, Petaling Jaya, Petaling, Selangor, 46400, Malaysia', '2026-05-20 19:39:42', '', 'Listening', NULL, NULL, 0, 'beginner', 0),
(29, 2, 10, 'English', 'online', '2026-05-29', '17:00:00', 47.00, 'cancelled', NULL, 0, 'student', 'Change of plans', NULL, '2026-05-21 03:29:09', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(30, 2, 3, 'English', 'face_to_face', '2026-05-25', '12:00:00', 50.00, 'completed', '2026-05-26 12:00:02', 1, NULL, NULL, 'Kuala Lumpur, Malaysia', '2026-05-21 03:29:44', '', 'Speaking', '', '2026-05-24 13:34:27', 0, 'beginner', 0),
(31, 2, 3, 'Mandarin', 'face_to_face', '2026-05-26', '18:00:00', 50.00, 'cancelled', NULL, 0, NULL, 'Payment not received before session time', 'Setapak, Kampung Padang Balang, Kuala Lumpur, 53000, Malaysia', '2026-05-22 12:17:41', '', 'Speaking, Reading', NULL, NULL, 0, 'beginner', 0),
(32, 2, 3, 'Mandarin', 'online', '2026-05-30', '15:00:00', 50.00, 'completed', '2026-06-01 17:05:50', 1, NULL, NULL, NULL, '2026-05-22 12:34:08', '', 'Speaking', 'https://meet.google.com/uev-betk-yup', '2026-05-26 22:17:04', 0, 'beginner', 1),
(33, 2, 3, 'Mandarin', 'online', '2026-06-15', '11:00:00', 50.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-05-22 12:34:08', '', 'Speaking', 'https://meet.google.com/uev-betk-yup', '2026-06-03 08:46:13', 0, 'beginner', 0),
(34, 2, 3, 'English', 'face_to_face', '2026-05-29', '18:00:00', 50.00, 'completed', '2026-06-03 12:00:01', 1, NULL, 'Payment not received before session time', 'ZUS Coffee, Avenue 5, Bangsar South, Pantai Dalam, Kuala Lumpur, 59200, Malaysia', '2026-05-22 14:42:14', '', 'Reading', NULL, NULL, 0, 'beginner', 0),
(35, 2, 3, 'English', 'face_to_face', '2026-05-27', '14:00:00', 50.00, 'disputed', '2026-05-29 13:14:34', 1, NULL, NULL, 'ZUS Coffee, Avenue 5, Bangsar South, Pantai Dalam, Kuala Lumpur, 59200, Malaysia', '2026-05-22 14:42:14', '', 'Reading', NULL, NULL, 0, 'beginner', 0),
(36, 4, 3, 'Mandarin', 'online', '2026-05-30', '13:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Change of plans', NULL, '2026-05-23 13:30:43', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(37, 4, 3, 'Mandarin', 'online', '2026-05-30', '14:00:00', 50.00, 'completed', '2026-06-01 17:06:21', 1, NULL, NULL, NULL, '2026-05-23 13:44:08', '', 'Listening', 'https://meet.google.com/uev-betk-yup', NULL, 0, 'beginner', 0),
(38, 4, 10, 'English', 'online', '2026-05-29', '06:00:00', 47.00, 'cancelled', NULL, 0, '', 'Auto-rejected: Tutor did not respond before booking date', NULL, '2026-05-23 14:00:15', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(39, 2, 3, 'Mandarin', 'online', '2026-05-28', '13:00:00', 50.00, 'cancelled', NULL, 0, NULL, 'Payment not received before deadline', NULL, '2026-05-25 03:33:54', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(40, 2, 6, 'English', 'online', '2026-05-30', '18:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Schedule conflict', NULL, '2026-05-25 03:55:15', '', 'Writing', NULL, NULL, 0, 'beginner', 0),
(41, 2, 3, 'Mandarin', 'online', '2026-05-28', '17:00:00', 50.00, 'cancelled', NULL, 0, NULL, 'Payment not received before deadline', NULL, '2026-05-25 04:11:36', '', 'Reading', NULL, NULL, 0, 'beginner', 0),
(42, 2, 9, 'Malay', 'face_to_face', '2026-05-28', '09:00:00', 40.00, 'cancelled', NULL, 0, NULL, 'Payment not received before deadline', 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(43, 2, 5, 'Japanese', 'face_to_face', '2026-05-29', '17:00:00', 45.00, 'cancelled', NULL, 0, 'student', 'No reason provided', 'Penang Times Square, Kampung Jawa Baru, Dato Keramat, Central George Town, Timur Laut, George Town, Pulau Pinang, 10150, Malaysia', '2026-05-28 10:11:56', '', 'Writing', NULL, NULL, 0, 'beginner', 0),
(44, 2, 5, 'Japanese', 'face_to_face', '2026-06-26', '14:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, 'Pulau Pinang, Malaysia', '2026-05-28 13:03:05', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(45, 2, 7, 'Mandarin', 'face_to_face', '2026-06-04', '20:00:00', 48.00, 'completed', '2026-06-06 12:00:10', 1, NULL, NULL, 'Universiti Teknologi Malaysia, Jalan Iman, Iskandar Puteri, Kulai, Johor, 81000, Malaysia', '2026-05-28 14:05:56', '', 'Speaking', NULL, NULL, 0, 'beginner', 1),
(46, 2, 10, 'English', 'online', '2026-05-30', '06:00:00', 47.00, 'cancelled', NULL, 0, 'tutor', 'Not available', NULL, '2026-05-28 14:09:30', '', 'Listening', NULL, NULL, 0, 'beginner', 0),
(47, 2, 5, 'Japanese', 'face_to_face', '2026-05-29', '16:00:00', 45.00, 'cancelled', NULL, 0, '', 'Auto-rejected: Tutor did not respond before booking date', 'Pulau Pinang, Malaysia', '2026-05-28 14:23:16', '', 'Reading', NULL, NULL, 0, 'advanced', 0),
(48, 2, 3, 'Japanese', 'online', '2026-06-27', '17:00:00', 60.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-05-28 15:28:28', '', 'Speaking', 'https://meet.google.com/uev-betk-yup', '2026-06-03 08:46:13', 0, 'intermediate', 0),
(49, 2, 3, 'Japanese', 'online', '2026-06-18', '18:00:00', 60.00, 'cancelled', NULL, 0, 'tutor', 'Not available', NULL, '2026-05-28 15:28:56', '', 'Reading', NULL, NULL, 0, 'master', 0),
(50, 2, 3, 'Japanese', 'online', '2026-05-30', '17:00:00', 60.00, 'completed', '2026-06-01 17:06:35', 1, NULL, NULL, NULL, '2026-05-28 15:40:07', '', 'Speaking', 'https://meet.google.com/zko-xyzk-tgr', '2026-05-29 14:31:28', 1, 'beginner', 1),
(51, 2, 5, 'Japanese', 'online', '2026-06-03', '09:00:00', 45.00, 'cancelled', NULL, 0, NULL, 'Payment not received before session time', NULL, '2026-06-02 14:32:04', '', 'Speaking', NULL, NULL, 0, 'intermediate', 0),
(52, 2, 10, 'English', 'online', '2026-06-30', '01:00:00', 47.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-02 16:38:08', '', 'Speaking', 'https://meet.google.com/uev-betk-yup', '2026-06-03 08:59:13', 0, 'beginner', 0),
(53, 2, 3, 'Japanese', 'online', '2026-06-04', '16:00:00', 60.00, 'completed', '2026-06-06 12:00:01', 1, NULL, NULL, NULL, '2026-06-02 20:17:45', '', 'Reading', NULL, NULL, 1, 'advanced', 1),
(54, 2, 3, 'Japanese', 'online', '2026-06-11', '16:00:00', 60.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-02 20:17:45', '', 'Reading', 'https://meet.google.com/ceb-jegw-gmj', '2026-06-09 14:21:55', 0, 'advanced', 0),
(55, 2, 3, 'Mandarin', 'online', '2026-06-25', '18:00:00', 60.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-02 23:49:01', '', 'Speaking', 'https://meet.google.com/uev-betk-yup', '2026-06-03 08:46:13', 0, 'advanced', 0),
(56, 2, 3, 'Japanese', 'face_to_face', '2026-06-26', '16:00:00', 60.00, 'confirmed', NULL, 0, NULL, NULL, 'Kuala Lumpur, Malaysia', '2026-06-03 01:05:05', '', 'Speaking', NULL, NULL, 0, 'master', 0),
(57, 2, 6, 'English', 'online', '2026-06-04', '11:00:00', 50.00, 'cancelled', '2026-06-05 12:00:02', 1, 'admin', 'Refunded due to dispute resolution', NULL, '2026-06-03 04:01:02', 'I want to focus on my grammar', 'Speaking, Listening, Reading, Writing', NULL, NULL, 1, 'intermediate', 1),
(58, 2, 6, 'English', 'online', '2026-06-04', '12:00:00', 50.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-06-03 04:01:02', 'I want to focus on my grammar', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'intermediate', 0),
(59, 2, 5, 'Japanese', 'face_to_face', '2026-06-17', '12:00:00', 45.00, 'rescheduled', NULL, 0, NULL, NULL, 'Pulau Pinang, Malaysia', '2026-06-03 11:46:21', '', 'Reading', NULL, NULL, 0, 'master', 0),
(62, 4, 7, 'Mandarin', 'online', '2026-06-25', '19:00:00', 48.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-06-06 03:46:16', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'beginner', 0),
(67, 4, 10, 'English', 'online', '2026-06-17', '10:00:00', 47.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-08 14:02:21', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'advanced', 0),
(68, 4, 10, 'English', 'online', '2026-06-24', '10:00:00', 47.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-08 14:02:21', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'advanced', 0),
(69, 4, 10, 'English', 'online', '2026-06-23', '10:00:00', 47.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-08 14:02:21', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'advanced', 0),
(70, 2, 5, 'Japanese', 'online', '2026-06-17', '09:00:00', 45.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-06-08 17:53:10', '', 'Listening', NULL, NULL, 0, 'beginner', 0),
(71, 2, 5, 'Japanese', 'online', '2026-06-24', '11:00:00', 45.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-06-08 17:53:10', '', 'Listening', NULL, NULL, 0, 'beginner', 0),
(72, 2, 5, 'Japanese', 'online', '2026-06-24', '09:00:00', 45.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-06-08 17:59:18', '', 'Speaking', NULL, NULL, 0, 'beginner', 0),
(73, 23, 5, 'Japanese', 'face_to_face', '2026-06-24', '12:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, 'Pulau Pinang, Malaysia', '2026-06-12 09:22:04', '', 'Reading', NULL, NULL, 0, 'intermediate', 0),
(74, 27, 5, 'Japanese', 'online', '2026-06-25', '12:00:00', 45.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:14:47', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(75, 27, 5, 'Japanese', 'online', '2026-06-25', '13:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:14:47', '', 'Speaking, Listening, Reading, Writing', 'https://meet.google.com/xvk-aohf-sei', '2026-06-13 12:51:49', 0, 'master', 0),
(76, 27, 5, 'Japanese', 'online', '2026-06-26', '16:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:23:31', '', 'Writing', 'https://meet.google.com/xvk-aohf-sei', '2026-06-13 12:51:49', 0, 'master', 0),
(77, 27, 5, 'Japanese', 'online', '2026-06-26', '12:00:00', 45.00, 'cancelled', NULL, 0, 'admin', 'Refunded due to dispute resolution', NULL, '2026-06-12 13:23:31', '', 'Writing', NULL, NULL, 0, 'master', 0),
(78, 27, 5, 'Japanese', 'online', '2026-06-24', '10:00:00', 45.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:23:31', '', 'Writing', NULL, NULL, 0, 'master', 0),
(79, 27, 5, 'Japanese', 'online', '2026-06-24', '17:00:00', 45.00, 'cancelled', NULL, 0, 'admin', 'Payment dispute rejected - student must pay correct amount', NULL, '2026-06-12 13:23:31', '', 'Writing', NULL, NULL, 0, 'master', 0),
(80, 27, 8, 'Korean', 'online', '2026-06-13', '10:00:00', 46.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:25:40', '', 'Listening', NULL, NULL, 1, 'master', 0),
(81, 25, 5, 'Japanese', 'online', '2026-06-15', '09:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:52:54', '', 'Speaking', 'https://meet.google.com/xvk-aohf-sei', '2026-06-13 12:51:49', 0, 'intermediate', 0),
(82, 25, 5, 'Japanese', 'online', '2026-06-15', '10:00:00', 45.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:52:54', '', 'Speaking', NULL, NULL, 0, 'intermediate', 0),
(83, 25, 3, 'Japanese', 'online', '2026-06-25', '16:00:00', 60.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-12 13:53:16', '', 'Speaking, Reading', NULL, NULL, 0, 'master', 0),
(84, 20, 5, 'Japanese', 'online', '2026-06-25', '11:00:00', 45.00, 'cancelled', NULL, 0, NULL, 'Payment rejected: PLEASE PRESS MONEY ALREADY DEDUCTED BUTTON TO RESUBMIT YOUR PROVE', NULL, '2026-06-12 19:01:06', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(85, 20, 5, 'Japanese', 'online', '2026-06-25', '17:00:00', 45.00, 'cancelled', NULL, 0, NULL, 'Payment rejected: PLEASE PRESS MONEY ALREADY DEDUCTED BUTTON TO RESUBMIT YOUR PROVE', NULL, '2026-06-12 19:01:06', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(86, 20, 5, 'Japanese', 'online', '2026-06-24', '17:00:00', 45.00, 'cancelled', NULL, 0, NULL, 'Payment rejected: MONEY ALREADY DEDUCTED BUTTON', NULL, '2026-06-12 19:01:06', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(87, 20, 5, 'Japanese', 'online', '2026-06-24', '16:00:00', 45.00, 'cancelled', NULL, 0, NULL, 'Payment rejected: MONEY ALREADY DEDUCTED BUTTON', NULL, '2026-06-12 19:01:06', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(88, 20, 8, 'Korean', 'online', '2026-06-28', '13:00:00', 46.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-13 02:43:45', '', 'Listening, Writing', NULL, NULL, 0, 'advanced', 0),
(89, 20, 8, 'Korean', 'online', '2026-06-28', '14:00:00', 46.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-13 02:43:45', '', 'Listening, Writing', NULL, NULL, 0, 'advanced', 0),
(90, 20, 5, 'Japanese', 'face_to_face', '2026-06-16', '12:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, 'Pulau Pinang, Malaysia', '2026-06-13 02:46:41', '', 'Speaking', NULL, NULL, 0, 'intermediate', 0),
(91, 20, 3, 'Japanese', 'online', '2026-06-25', '14:00:00', 60.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-06-13 03:35:42', ' Partial payment received. Remaining balance: RM 2.86', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(92, 20, 3, 'Japanese', 'online', '2026-06-25', '15:00:00', 60.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-13 03:35:42', '', 'Speaking, Listening, Reading, Writing', NULL, NULL, 0, 'master', 0),
(93, 20, 5, 'Japanese', 'online', '2026-06-25', '17:00:00', 45.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-06-13 03:57:32', '', 'Listening, Reading', NULL, NULL, 0, 'master', 0),
(94, 20, 5, 'Japanese', 'online', '2026-06-25', '16:00:00', 45.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-06-13 03:57:32', ' Partial payment received. Remaining balance: RM 2.14', 'Listening, Reading', NULL, NULL, 0, 'master', 0),
(95, 20, 5, 'Japanese', 'online', '2026-06-30', '12:00:00', 45.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-06-13 03:57:32', '', 'Listening, Reading', 'https://meet.google.com/xvk-aohf-sei', '2026-06-13 12:51:49', 0, 'master', 0);

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `issue_type` varchar(50) DEFAULT 'other',
  `dispute_type` enum('booking','payment') DEFAULT 'booking',
  `message` text DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','resolved','escalated','rejected') DEFAULT 'pending',
  `resolution_type` enum('student_tutor','admin') DEFAULT 'student_tutor',
  `resolution_note` text DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `escalated_at` datetime DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_account_name` varchar(100) DEFAULT NULL,
  `escalation_reason` text DEFAULT NULL,
  `notification_sent` datetime DEFAULT NULL,
  `resolution_requested` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disputes`
--

INSERT INTO `disputes` (`id`, `booking_id`, `payment_id`, `student_id`, `tutor_id`, `issue_type`, `dispute_type`, `message`, `proof_image`, `status`, `resolution_type`, `resolution_note`, `resolved_by`, `resolved_at`, `created_at`, `escalated_at`, `preferred_date`, `preferred_time`, `bank_name`, `bank_account_number`, `bank_account_name`, `escalation_reason`, `notification_sent`, `resolution_requested`) VALUES
(1, 32, NULL, 2, 3, 'wrong_materials', 'booking', '', NULL, 'resolved', 'student_tutor', 'Tutor resolved the issue', 3, '2026-05-29 17:35:08', '2026-05-29 14:06:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 2, NULL, 2, 10, 'tutor_no_show', 'booking', 'Tutor didn\'t even give meeting links', NULL, 'resolved', 'admin', 'Refund of RM 47.00 processed. Receipt: RFD-20260607-000003 ', 1, '2026-06-07 06:26:50', '2026-06-07 01:58:24', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 2, NULL, 2, 10, 'tutor_no_show', 'booking', 'Tutor didn\'t even give meeting links', NULL, 'resolved', 'admin', 'Refund of RM 47.00 processed. Receipt: RFD-20260608-000004 ', 1, '2026-06-08 19:54:43', '2026-06-07 02:01:26', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 57, NULL, 2, 6, 'tutor_no_show', 'booking', 'I HAVE BEEN WAITING AT OUTSIDE FOR 30 MINUTES NO ONE LET ME IN', '../uploads/reports/1780771430_f974b364a69bc1aac852f69d4d2bc8312adf1992.png', 'resolved', 'admin', 'Refund of RM 50.00 processed. Receipt: RFD-20260607-000005 ', 1, '2026-06-07 06:48:26', '2026-06-07 02:43:50', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 59, NULL, 2, 5, 'wrong_materials', 'booking', 'YOU HAVE SUBMITTED WRONG MATERIALS PLEASE RESUBMIT BASED ON WHAT I PICK ON THE LANGUAGE', NULL, 'escalated', 'student_tutor', NULL, NULL, NULL, '2026-06-07 02:47:49', '2026-06-09 22:14:31', NULL, NULL, NULL, NULL, NULL, 'Tutor did not respond within 2 days', '2026-06-09 22:14:32', NULL),
(9, 32, NULL, 4, 5, 'money_deducted', 'payment', 'Money was deducted but I still want the session at the original time.', NULL, 'resolved', 'student_tutor', 'Issue resolved between student and tutor. ', 1, '2026-06-08 21:28:46', '2026-06-08 21:15:10', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'complete'),
(10, 79, 58, 27, 5, 'money_deducted', 'payment', '=== DISPUTE REPORT ===\n\nPayment ID: #58\nBooking ID: #79\nResolution: Refund\nDescription: I HAVE TRANSFTER AT 10 PM\nProof: uploads/dispute_proofs/dispute_1781274445_8587.png\n\n=== BANK DETAILS FOR REFUND ===\nBank: MAYBANK\nAccount Number: 122233\nAccount Name: JAMES\n', 'uploads/dispute_proofs/dispute_1781274445_8587.png', 'resolved', 'admin', 'Payment dispute rejected. Booking cancelled. Student must make correct payment. REJECTION REASON: ＮＯＴ　ＢＥＩＮＧ　ＲＥＣＥＩＶＥＤ　ＩＮ　ＴＨＥ　ＤＵＩＴ　ＮＯＷ　ＡＣＣＯＵＮＴ', 1, '2026-06-13 01:40:07', '2026-06-12 22:27:25', NULL, NULL, NULL, 'MAYBANK', '122233', 'JAMES', NULL, NULL, 'refund'),
(12, 77, 63, 27, 5, 'money_deducted', 'payment', 'Payment ID: #62\nBooking ID: #76\nResolution: Refund\nDescription: I HAVE TRANSFER AT 12 AM\nProof: uploads/dispute_proofs/dispute_1781277934_5448.png\nBank: MAYBANK\nAccount: 122233\nName: JAMES\n', 'uploads/dispute_proofs/dispute_1781277934_5448.png', 'resolved', 'student_tutor', 'Refund of RM 45.00 processed. Receipt: RFD-20260613-000012 ', 1, '2026-06-13 01:36:52', '2026-06-12 23:25:34', NULL, NULL, NULL, 'MAYBANK', '122233', 'JAMES', NULL, NULL, 'refund'),
(14, 76, 62, 27, 5, 'money_deducted', 'payment', 'Payment ID: #62\nBooking ID: #76\nResolution: Reschedule\nDescription: HH\nProof: uploads/dispute_proofs/dispute_1781282742_3848.png\nPreferred Reschedule Date/Time: 2026-06-26T16:00\n', 'uploads/dispute_proofs/dispute_1781282742_3848.png', 'resolved', 'student_tutor', 'Session rescheduled from 26 Jun 2026 at 1:00 PM to 26 Jun 2026 at 4:00 PM ', 1, '2026-06-13 00:48:14', '2026-06-13 00:45:42', NULL, '2026-06-26', '16:00:00', NULL, NULL, NULL, NULL, NULL, 'reschedule');

-- --------------------------------------------------------

--
-- Table structure for table `learning_materials`
--

CREATE TABLE `learning_materials` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `booking_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `material_url` varchar(500) DEFAULT NULL,
  `is_url` tinyint(1) DEFAULT 0,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `material_type` enum('pre','post') DEFAULT 'pre',
  `proficiency_level` varchar(20) DEFAULT 'beginner',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `learning_materials`
--

INSERT INTO `learning_materials` (`id`, `tutor_id`, `student_id`, `booking_id`, `title`, `description`, `feedback`, `file_name`, `file_path`, `material_url`, `is_url`, `file_type`, `file_size`, `uploaded_at`, `material_type`, `proficiency_level`, `updated_at`) VALUES
(2, 3, 2, 37, 'Self Introduction', '', NULL, '', '', 'https://youtu.be/McZW0iDsZns?si=thX0_WIP6M2xXOU9', 1, 'url', 0, '2026-05-24 13:55:52', 'pre', 'beginner', '2026-05-24 19:34:10'),
(5, 3, 4, 37, 'Video HSK Listening Practise', 'A simple short story about Miss Wang \'s weekly life.', 'Please watch and understand what it means', 'LISTENING PRACTISE.mp4', '../uploads/materials/1779622780_5ad828dfb733b840.mp4', NULL, 0, 'video/mp4', 7811434, '2026-05-24 19:39:40', 'pre', 'beginner', '2026-05-24 18:26:49'),
(6, 3, 4, 37, 'Listening', '', '', 'How to say Hello in Chinese.mp3', '../uploads/materials/1779624489_f7d7cb309ec55337.mp3', NULL, 0, 'audio/mpeg', 2163853, '2026-05-24 20:08:09', 'post', 'beginner', '2026-05-24 18:26:49'),
(10, 3, 2, 48, 'Japanese greetings', '', '', 'japanese_greetings.mp3', '../uploads/materials/1780049592_35ba74d1b7aed1b6.mp3', NULL, 0, 'audio/mpeg', 3049885, '2026-05-29 18:13:12', 'pre', 'beginner', '2026-05-29 10:13:12');

-- --------------------------------------------------------

--
-- Table structure for table `meeting_logs`
--

CREATE TABLE `meeting_logs` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `meeting_id` varchar(255) DEFAULT NULL,
  `participant_name` varchar(255) DEFAULT NULL,
  `participant_role` enum('tutor','student') DEFAULT NULL,
  `join_time` datetime DEFAULT NULL,
  `leave_time` datetime DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meeting_logs`
--

INSERT INTO `meeting_logs` (`id`, `booking_id`, `meeting_id`, `participant_name`, `participant_role`, `join_time`, `leave_time`, `duration_minutes`) VALUES
(2, 32, NULL, 'Sharon', 'student', '2026-05-27 15:13:13', '2026-05-27 15:58:13', 45),
(3, 32, NULL, 'Feng Xi', 'tutor', '2026-05-27 15:15:33', '2026-05-27 16:00:33', 45),
(4, 1, NULL, 'Haruka Tan', 'tutor', '2026-06-13 13:03:45', '2026-06-13 13:38:02', 34),
(5, 1, NULL, 'Sharon', 'student', '2026-06-13 13:07:46', '2026-06-13 13:38:14', 30),
(6, 1, NULL, 'Haruka Tan', 'tutor', '2026-06-13 13:38:33', '2026-06-13 13:45:20', 7);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(30) DEFAULT 'general',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `link`, `is_read`, `created_at`) VALUES
(1, 7, 'Booking Cancelled', 'Sharon cancelled their Mandarin lesson on Wednesday, 20 May 2026 at 6:00 PM.', 'booking_cancelled', 'booking_detail.php?id=25', 0, '2026-05-19 22:50:40'),
(2, 2, 'Booking Cancelled', 'You have cancelled your Mandarin lesson with Alicia Wong on Wednesday, 20 May 2026 at 6:00 PM.', 'booking_cancelled', 'booking_status.php', 1, '2026-05-19 22:50:40'),
(3, 1, 'Payment Dispute Reported', 'Student #2 reported payment issue for booking #2. Amount: RM47', 'payment_dispute', 'admin_payments.php?status=disputed', 0, '2026-05-19 23:20:56'),
(4, 2, 'Dispute Submitted', 'Your payment dispute has been submitted. Admin will review within 24 hours.', 'payment_dispute', 'my_payments.php', 1, '2026-05-19 23:20:56'),
(5, 4, 'Session Auto-Completed', 'Your Japanese session has been automatically completed. If you have any issues, please contact support.', 'auto_completed', 'booking_detail.php?id=6', 1, '2026-05-20 00:19:29'),
(6, 5, 'Session Auto-Completed', 'Your Japanese session has been automatically completed. Payment will be processed.', 'auto_completed', 'tutor_booking_detail.php?id=6', 0, '2026-05-20 00:19:29'),
(7, 2, 'Session Auto-Completed', 'Your Malay session has been automatically completed. If you have any issues, please contact support.', 'auto_completed', 'booking_detail.php?id=21', 1, '2026-05-20 02:00:01'),
(8, 9, 'Session Auto-Completed', 'Your Malay session has been automatically completed. Payment will be processed.', 'auto_completed', 'tutor_booking_detail.php?id=21', 0, '2026-05-20 02:00:01'),
(9, 9, 'Student Confirmed Session', 'The student has confirmed the Malay session. Please confirm to complete.', 'confirmation', 'tutor_booking_detail.php?id=21', 0, '2026-05-20 03:46:08'),
(10, 2, 'Session Completed! 🎉', 'Your Malay session has been completed. Thank you for attending!', 'completed', 'booking_detail.php?id=21', 1, '2026-05-20 03:46:08'),
(11, 9, 'Session Completed! 🎉', 'Your Malay session has been completed. Payment will be processed.', 'completed', 'tutor_booking_detail.php?id=21', 0, '2026-05-20 03:46:08'),
(12, 2, 'Booking Confirmed! 🎉', 'Your Mandarin session with Feng Xi on Thursday, May 21, 2026 at 12:00 PM has been confirmed. Please proceed to payment.', 'booking_confirmed', 'booking_detail.php?id=28', 1, '2026-05-21 04:23:28'),
(13, 2, 'Booking Accepted! 🎉', 'Your English session with Feng Xi on Monday, May 25, 2026 at 12:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=30', 1, '2026-05-21 11:45:08'),
(14, 2, 'Booking Accepted! 🎉', 'Your English session with Feng Xi on Monday, May 25, 2026 at 12:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=30', 1, '2026-05-21 11:45:14'),
(15, 2, 'Session Cancelled', 'Your English session on Thursday, 21 May 2026 at 01:00 AM has been cancelled because payment was not received before the session. Please book a new session.', 'auto_cancelled', 'booking_status.php?id=2', 1, '2026-05-21 13:53:07'),
(16, 2, 'Session Cancelled', 'Your Malay session on Wednesday, 20 May 2026 at 09:00 AM has been cancelled because payment was not received before the session. Please book a new session.', 'auto_cancelled', 'booking_status.php?id=23', 1, '2026-05-21 13:53:07'),
(18, 2, 'Cancellation Email Sent', 'An email notification about your cancelled session has been sent.', 'auto_cancelled_email', 'booking_status.php?id=2', 1, '2026-05-21 13:53:14'),
(19, 2, 'Cancellation Email Sent', 'An email notification about your cancelled session has been sent.', 'auto_cancelled_email', 'booking_status.php?id=23', 1, '2026-05-21 13:53:20'),
(20, 2, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Tuesday, May 26, 2026 at 6:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=31', 1, '2026-05-22 20:18:06'),
(21, 2, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 3:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=32', 1, '2026-05-22 20:35:24'),
(22, 2, 'Booking Accepted! 🎉', 'Your English session with Feng Xi on Friday, May 29, 2026 at 2:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=35', 1, '2026-05-22 23:47:57'),
(23, 2, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 6:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=33', 1, '2026-05-23 00:00:00'),
(24, 2, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 3:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=32', 1, '2026-05-23 00:05:42'),
(26, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 27 May 2026 at 2:00 PM', 'reschedule', NULL, 1, '2026-05-23 19:34:07'),
(29, 3, 'Booking Cancelled', 'Sarah cancelled their Mandarin lesson on Saturday, 30 May 2026 at 1:00 PM. Reason: Change of plans', 'booking_cancelled', 'booking_detail.php?id=36', 0, '2026-05-23 21:43:35'),
(30, 4, 'Booking Cancelled', 'You have cancelled your Mandarin lesson with Feng Xi on Saturday, 30 May 2026 at 1:00 PM. Reason: Change of plans', 'booking_cancelled', 'booking_status.php', 1, '2026-05-23 21:43:35'),
(31, 4, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 1:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=37', 1, '2026-05-23 22:06:12'),
(32, 3, 'New Reschedule Request', 'Student has requested to reschedule a session.', 'reschedule', NULL, 0, '2026-05-23 22:33:10'),
(33, 4, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 30 May 2026 at 2:00 PM', 'reschedule', NULL, 1, '2026-05-23 22:36:55'),
(34, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your English session on Monday, May 25, 2026 at 12:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=30', 1, '2026-05-24 13:34:27'),
(35, 4, 'New Learning Material', 'Feng Xi uploaded: Self Introduction', 'learning_materials.php?booking', NULL, 1, '2026-05-24 13:55:52'),
(36, 4, 'New Learning Material', 'Feng Xi uploaded: Self Introduction', 'learning_materials.php?booking', NULL, 1, '2026-05-24 13:56:03'),
(37, 4, 'New Learning Material', 'Feng Xi uploaded: Lesson 1 Note', 'learning_materials.php?booking', NULL, 1, '2026-05-24 14:04:41'),
(38, 4, 'New Learning Material', 'Feng Xi uploaded: Video HSK Listening Practise', 'learning_materials.php?booking', NULL, 1, '2026-05-24 19:39:40'),
(39, 4, 'New Learning Material', 'Feng Xi uploaded: Listening', 'learning_materials.php?booking', NULL, 1, '2026-05-24 20:08:09'),
(40, 4, 'New Assignment: Self Introduction', 'You have a new assignment: Self Introduction (Due: 30 May 2026, 12:00 PM)', 'assignment', 'assignments.php?booking_id=37', 1, '2026-05-24 22:42:49'),
(41, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 01 Jan 1970 at 1:00 AM', 'reschedule', NULL, 1, '2026-05-25 11:35:20'),
(42, 4, 'New Assignment: Essay', 'You have a new assignment: Essay (Due: 27 May 2026, 12:00 AM)', 'assignment', 'assignments.php?booking_id=37', 1, '2026-05-25 11:42:32'),
(43, 2, 'Booking Accepted!', 'Your booking for Mandarin on 28 May 2026 at 5:00 PM has been accepted by tutor Feng Xi. Please proceed to payment.', 'booking', NULL, 1, '2026-05-25 12:30:11'),
(44, 2, 'Booking Accepted!', 'Your booking for Mandarin on 28 May 2026 at 5:00 PM has been accepted by tutor Feng Xi. Please proceed to payment.', 'booking', NULL, 1, '2026-05-25 12:30:47'),
(45, 2, 'Booking Accepted!', 'Your booking for Mandarin on 28 May 2026 at 5:00 PM has been accepted by tutor Feng Xi. Please proceed to payment.', 'booking', NULL, 1, '2026-05-25 12:37:13'),
(46, 2, 'Session Auto-Completed', 'Your English session with Feng Xi on 25 May 2026 has been automatically completed.', 'auto_completed', 'booking_detail.php?id=30', 1, '2026-05-26 12:00:02'),
(47, 2, 'Reschedule Request Cancelled', 'You have cancelled your reschedule request for Korean on 13 Jun 2026 at 12:00 PM. Your original booking remains confirmed.', 'booking', NULL, 1, '2026-05-26 15:40:42'),
(48, 4, 'New Learning Material', 'Feng Xi uploaded: Lesson 1 Note', 'learning_materials.php?booking', NULL, 1, '2026-05-26 16:41:08'),
(49, 4, 'New Learning Material', 'Feng Xi uploaded: Lesson 2', 'learning_materials.php?booking', NULL, 1, '2026-05-26 16:45:37'),
(50, 2, 'Session Cancelled', 'Your Mandarin session on Tuesday, 26 May 2026 at 06:00 PM has been cancelled because payment was not received before the session. Please book a new session.', 'auto_cancelled', 'booking_status.php?id=31', 1, '2026-05-26 18:00:16'),
(51, 2, 'Cancellation Email Sent', 'An email notification about your cancelled session has been sent.', 'auto_cancelled_email', 'booking_status.php?id=31', 1, '2026-05-26 18:00:23'),
(52, 2, 'Session Cancelled', 'Your Mandarin session on Wednesday, May 27, 2026 at 2:00 PM was cancelled because payment was not received before the session time.', 'auto_cancelled', 'booking_status.php?id=17', 1, '2026-05-26 18:12:53'),
(53, 3, 'Session Auto-Cancelled', 'Your Mandarin session with Sharon on Wednesday, May 27, 2026 at 2:00 PM was cancelled because payment was not received.', 'tutor_auto_cancelled', 'tutor_booking_detail.php?id=17', 0, '2026-05-26 18:12:59'),
(54, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:06'),
(55, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:13'),
(56, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:18'),
(57, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:25'),
(58, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:31'),
(59, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:36'),
(60, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:42'),
(61, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:56:48'),
(62, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:57:46'),
(63, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=32', 1, '2026-05-26 21:57:52'),
(64, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link', 'booking_detail.php?id=32', 1, '2026-05-26 22:08:53'),
(65, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link', 'booking_detail.php?id=32', 1, '2026-05-26 22:09:09'),
(66, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link', 'booking_detail.php?id=32', 1, '2026-05-26 22:16:52'),
(67, 2, 'Meeting Link Updated', 'Your tutor has updated the meeting link for your Mandarin session on Saturday, May 30, 2026 at 3:00 PM.', 'meeting_link', 'booking_detail.php?id=32', 1, '2026-05-26 22:17:04'),
(68, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your Mandarin session on Saturday, May 30, 2026 at 6:00 PM.', 'meeting_link', 'booking_detail.php?id=33', 1, '2026-05-26 22:20:52'),
(69, 2, 'Session Cancelled', 'Your Mandarin session on Thursday, May 28, 2026 at 1:00 PM was cancelled because payment was not received before the session time.', 'auto_cancelled', 'booking_status.php?id=39', 1, '2026-05-27 00:00:01'),
(70, 3, 'Session Auto-Cancelled', 'Your Mandarin session with Sharon on Thursday, May 28, 2026 at 1:00 PM was cancelled because payment was not received.', 'tutor_auto_cancelled', 'tutor_booking_detail.php?id=39', 0, '2026-05-27 00:00:01'),
(71, 2, 'Session Report Available', 'Your tutor has submitted a session report for your English session on 25 May 2026', 'session_report', 'booking_detail.php?id=30', 1, '2026-05-27 00:49:02'),
(72, 3, 'New Reschedule Request', 'Student has requested to reschedule a session.', 'reschedule', NULL, 0, '2026-05-27 15:44:34'),
(73, 3, 'Student Confirmed', 'Your student Sharon has confirmed they attended the English session.', 'session_confirmation', 'tutor_booking_detail.php?id=35', 0, '2026-05-27 18:22:04'),
(74, 2, 'Session Cancelled', 'Your English session on Friday, May 29, 2026 at 6:00 PM was cancelled because payment was not received before the session time.', 'auto_cancelled', 'booking_status.php?id=34', 1, '2026-05-28 00:00:02'),
(75, 3, 'Session Auto-Cancelled', 'Your English session with Sharon on Friday, May 29, 2026 at 6:00 PM was cancelled because payment was not received.', 'tutor_auto_cancelled', 'tutor_booking_detail.php?id=34', 0, '2026-05-28 00:00:02'),
(76, 2, 'Reschedule Request Rejected', 'Dear Sharon,\n\nYour reschedule request for Japanese session has been rejected because the tutor did not respond before your original booking date.\n\nOriginal Session: Thursday, May 28, 2026 at 12:00 PM\nYou Requested: Wednesday, May 13, 2026 at 4:00 PM\n\nYour original session remains confirmed. Please attend as scheduled.\n\n- Kyoshi Team', 'reschedule_rejected', NULL, 1, '2026-05-28 00:00:04'),
(77, 5, 'Reschedule Request Rejected - No Response', 'Dear Haruka Tan,\n\nYou did not respond to a reschedule request from Sharon for Japanese session before the booking date.\n\nOriginal Session: Thursday, May 28, 2026 at 12:00 PM\nStudent Requested: Wednesday, May 13, 2026 at 4:00 PM\n\nThe request has been rejected. The student will keep the original schedule.\n\nPlease respond to future reschedule requests promptly.\n\n- Kyoshi Team', 'warning', NULL, 0, '2026-05-28 00:00:04'),
(78, 5, 'Meeting Link Required', 'Your Japanese session with Sharon on 28 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-05-28 00:15:02'),
(79, 5, 'Meeting Link Required', 'Your Japanese session with Sharon on 28 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-05-28 00:30:02'),
(80, 5, 'Meeting Link Required', 'Your Japanese session with Sharon on 28 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-05-28 00:45:04'),
(81, 3, 'New Assignment Submission', 'A student has submitted an assignment: Self Introduction', 'submission', 'assignment_overview.php', 0, '2026-05-28 00:52:39'),
(82, 5, 'Meeting Link Required', 'Your Japanese session with Sharon on 28 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-05-28 01:00:02'),
(83, 5, 'Meeting Link Required', 'Your Japanese session with Sharon on 28 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-05-28 01:15:03'),
(84, 2, 'Session Cancelled', 'Your Mandarin session on Thursday, May 28, 2026 at 5:00 PM was cancelled because payment was not received before the session time.', 'auto_cancelled', 'booking_status.php?id=41', 1, '2026-05-28 13:42:57'),
(85, 3, 'Session Auto-Cancelled', 'Your Mandarin session with Sharon on Thursday, May 28, 2026 at 5:00 PM was cancelled because payment was not received.', 'tutor_auto_cancelled', 'tutor_booking_detail.php?id=41', 0, '2026-05-28 13:42:57'),
(86, 2, 'Session Cancelled', 'Your Malay session on Thursday, May 28, 2026 at 9:00 AM was cancelled because payment was not received before the session time.', 'auto_cancelled', 'booking_status.php?id=42', 1, '2026-05-28 13:43:11'),
(87, 9, 'Session Auto-Cancelled', 'Your Malay session with Sharon on Thursday, May 28, 2026 at 9:00 AM was cancelled because payment was not received.', 'tutor_auto_cancelled', 'tutor_booking_detail.php?id=42', 0, '2026-05-28 13:43:11'),
(88, 2, 'Session Cancelled', 'Your English session on Friday, May 29, 2026 at 12:00 PM was cancelled because payment was not received before the session time.', 'auto_cancelled', 'booking_status.php?id=24', 1, '2026-05-28 13:43:23'),
(89, 10, 'Session Auto-Cancelled', 'Your English session with Sharon on Friday, May 29, 2026 at 12:00 PM was cancelled because payment was not received.', 'tutor_auto_cancelled', 'tutor_booking_detail.php?id=24', 0, '2026-05-28 13:43:23'),
(90, 5, 'Student Confirmed', 'Your student Sharon has confirmed they attended the Japanese session.', 'session_confirmation', 'tutor_booking_detail.php?id=1', 0, '2026-05-28 18:28:37'),
(91, 5, 'Booking Cancelled', 'Sharon cancelled their Japanese lesson on Friday, 29 May 2026 at 5:00 PM. Reason: No reason provided', 'booking_cancelled', 'booking_detail.php?id=43', 0, '2026-05-28 19:11:27'),
(92, 2, 'Booking Cancelled', 'You have cancelled your Japanese lesson with Haruka Tan on Friday, 29 May 2026 at 5:00 PM. Reason: No reason provided', 'booking_cancelled', 'booking_status.php', 1, '2026-05-28 19:11:27'),
(93, 6, 'Booking Cancelled', 'Sharon cancelled their English lesson on Saturday, 30 May 2026 at 6:00 PM. Reason: Schedule conflict', 'booking_cancelled', 'booking_detail.php?id=40', 0, '2026-05-28 19:19:05'),
(94, 2, 'Booking Cancelled', 'You have cancelled your English lesson with Daniel Lee on Saturday, 30 May 2026 at 6:00 PM. Reason: Schedule conflict', 'booking_cancelled', 'booking_status.php', 1, '2026-05-28 19:19:05'),
(95, 10, 'Booking Cancelled', 'Sharon cancelled their English lesson on Friday, 29 May 2026 at 5:00 PM. Reason: Change of plans', 'booking_cancelled', 'tutor_booking_detail.php?id=29', 0, '2026-05-28 19:48:53'),
(96, 5, 'Booking Cancelled', 'Sharon cancelled their Japanese lesson on Friday, 29 May 2026 at 4:00 PM. Reason: Change of plans', 'booking_cancelled', 'tutor_booking_detail.php?id=27', 0, '2026-05-28 19:48:58'),
(97, 2, 'Bookings Cancelled', 'You have cancelled 2 booking(s). Reason: Change of plans', 'booking_cancelled', 'booking_status.php', 1, '2026-05-28 19:49:03'),
(98, 3, 'New Reschedule Request', 'Student has requested to reschedule a session.', 'reschedule', NULL, 0, '2026-05-28 19:55:33'),
(99, 2, 'Booking Accepted!', 'Your booking for Japanese on 26 Jun 2026 at 2:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', NULL, 1, '2026-05-28 21:06:44'),
(100, 5, 'New Reschedule Request', 'Student has requested to reschedule a session from 26 Jun 2026, 2:00 PM to 29 May 2026, 4:00 PM', 'reschedule', NULL, 0, '2026-05-28 21:09:52'),
(101, 8, 'New Reschedule Request', 'Student has requested to reschedule a session from 13 Jun 2026, 12:00 PM to 30 May 2026, 4:00 PM', 'reschedule', NULL, 0, '2026-05-28 21:10:04'),
(102, 2, 'Reschedule Request Cancelled', 'You have cancelled your reschedule request for Japanese session on 26 Jun 2026, 2:00 PM. Your original booking remains confirmed.', 'booking', 'booking_detail.php?id=44', 1, '2026-05-28 21:16:35'),
(103, 5, 'Reschedule Request Cancelled by Student', 'Student has cancelled their reschedule request for Japanese session on 26 Jun 2026, 2:00 PM. The original booking remains confirmed.', 'booking', 'tutor_booking_detail.php?id=44', 0, '2026-05-28 21:16:35'),
(104, 2, 'Reschedule Request Cancelled', 'You have cancelled your reschedule request for Mandarin session on 30 May 2026, 3:00 PM. Your original booking remains confirmed.', 'booking', 'booking_detail.php?id=32', 1, '2026-05-28 21:39:36'),
(105, 3, 'Reschedule Request Cancelled by Student', 'Student has cancelled their reschedule request for Mandarin session on 30 May 2026, 3:00 PM. The original booking remains confirmed.', 'booking', 'tutor_booking_detail.php?id=32', 0, '2026-05-28 21:39:36'),
(106, 3, 'New Reschedule Request', 'Student has requested to reschedule a session from 30 May 2026, 3:00 PM to 30 May 2026, 5:00 PM', 'reschedule', NULL, 0, '2026-05-28 21:39:50'),
(107, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 30 May 2026 at 4:00 PM', 'reschedule', NULL, 1, '2026-05-28 21:59:09'),
(108, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 30 May 2026 at 4:00 PM', 'reschedule', NULL, 1, '2026-05-28 21:59:14'),
(109, 2, 'Booking Accepted!', 'Your booking for Korean on 06 Jun 2026 at 12:00 PM has been accepted by tutor Kim Jisoo. Please proceed to payment.', 'booking', NULL, 1, '2026-05-28 21:59:37'),
(110, 2, 'Booking Accepted!', 'Your booking for Mandarin on 04 Jun 2026 at 8:00 PM has been accepted by tutor Alicia Wong. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=45', 1, '2026-05-28 22:06:42'),
(111, 2, 'Booking Declined', 'Your booking for English on 30 May 2026 at 6:00 AM has been declined by tutor Aina Yusuf. Reason: Not available', 'booking', NULL, 1, '2026-05-28 22:19:56'),
(112, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 15 Jun 2026 at 11:00 AM', 'reschedule', NULL, 1, '2026-05-28 23:17:11'),
(113, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot unavailable', 'reschedule', NULL, 1, '2026-05-28 23:20:23'),
(114, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot unavailable', 'reschedule', NULL, 1, '2026-05-28 23:20:27'),
(115, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot unavailable', 'reschedule', NULL, 1, '2026-05-28 23:20:32'),
(116, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot unavailable', 'reschedule', NULL, 1, '2026-05-28 23:20:37'),
(117, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot unavailable', 'reschedule', NULL, 1, '2026-05-28 23:20:41'),
(118, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot unavailable', 'reschedule', NULL, 1, '2026-05-28 23:20:45'),
(119, 2, 'Booking Declined', 'Your booking for Japanese on 18 Jun 2026 at 6:00 PM has been declined by tutor Feng X. Reason: Not available', 'booking', NULL, 1, '2026-05-28 23:29:31'),
(120, 2, 'Booking Accepted!', 'Your booking for Japanese on 30 May 2026 at 5:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=48', 1, '2026-05-28 23:29:40'),
(121, 3, 'New Reschedule Request', 'Student has requested to reschedule a session from 30 May 2026, 5:00 PM to 27 Jun 2026, 5:00 PM', 'reschedule', NULL, 0, '2026-05-28 23:30:56'),
(122, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 27 Jun 2026 at 5:00 PM', 'reschedule', NULL, 1, '2026-05-28 23:38:12'),
(123, 2, 'Booking Accepted!', 'Your booking for Japanese on 30 May 2026 at 5:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=50', 1, '2026-05-28 23:40:41'),
(124, 3, 'New Reschedule Request', 'Student has requested to reschedule a session from 30 May 2026, 5:00 PM to 29 May 2026, 5:00 PM', 'reschedule', NULL, 0, '2026-05-28 23:42:49'),
(125, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 30 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=20', 0, '2026-05-29 00:47:38'),
(126, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot not available', 'reschedule', NULL, 1, '2026-05-29 04:48:54'),
(127, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot not available', 'reschedule', NULL, 1, '2026-05-29 04:48:58'),
(128, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot not available', 'reschedule', NULL, 1, '2026-05-29 04:49:03'),
(129, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot not available', 'reschedule', NULL, 1, '2026-05-29 04:49:07'),
(130, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Time slot not available', 'reschedule', NULL, 1, '2026-05-29 04:49:12'),
(131, 3, 'Meeting Link Required', 'Your Japanese session with Sharon on 30 May 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=50', 0, '2026-05-29 05:00:02'),
(132, 3, 'New Reschedule Request', 'Student has requested to reschedule a session from 30 May 2026, 5:00 PM to 25 Jun 2026, 5:00 PM', 'reschedule', NULL, 0, '2026-05-29 05:20:18'),
(133, 2, 'Reschedule Request Declined', 'Your tutor has declined your reschedule request. Reason: Not available', 'reschedule', NULL, 1, '2026-05-29 05:21:04'),
(134, 2, 'Reschedule Request Rejected', 'Dear Sharon,\n\nYour reschedule request for Malay session has been rejected because the tutor did not respond before your original booking date.\n\nOriginal Session: Friday, May 29, 2026 at 9:00 AM\nYou Requested: Tuesday, May 26, 2026 at 9:00 AM\n\nYour original session remains confirmed. Please attend as scheduled.\n\n- Kyoshi Team', 'reschedule_rejected', NULL, 1, '2026-05-29 13:03:12'),
(135, 9, 'Reschedule Request Rejected - No Response', 'Dear Farah Nabila,\n\nYou did not respond to a reschedule request from Sharon for Malay session before the booking date.\n\nOriginal Session: Friday, May 29, 2026 at 9:00 AM\nStudent Requested: Tuesday, May 26, 2026 at 9:00 AM\n\nThe request has been rejected. The student will keep the original schedule.\n\nPlease respond to future reschedule requests promptly.\n\n- Kyoshi Team', 'warning', NULL, 0, '2026-05-29 13:03:12'),
(136, 2, 'Session Disputed', 'Your English session on 27 May 2026 has been marked as DISPUTED.\n\nNeither party attended the session. Session marked as disputed.\n\nPlease contact support for assistance.', 'disputed', 'booking_detail.php?id=35', 1, '2026-05-29 13:14:34'),
(137, 3, 'Session Disputed', 'Your English session on 27 May 2026 has been marked as DISPUTED.\n\nNeither party attended the session. Session marked as disputed.\n\nPlease contact support for assistance.', 'disputed', 'tutor_booking_detail.php?id=35', 0, '2026-05-29 13:14:34'),
(138, 2, 'Cancellation Email Sent', 'An email notification about your cancelled session has been sent.', 'auto_cancelled_email', 'booking_status.php?id=24', 1, '2026-05-29 13:34:29'),
(139, 2, 'Issue Reported - Tutor Notified', 'Your issue has been reported. The tutor has been notified and will contact you to resolve it within 48 hours.', 'dispute', 'booking_detail.php?id=32', 1, '2026-05-29 14:06:53'),
(140, 3, 'Student Reported Issue - Please Resolve', 'A student has reported an issue with your Mandarin session.\n\nIssue: Wrong materials\nMessage: No details provided\n\nPlease resolve this issue within 48 hours, otherwise it will be escalated to admin.', 'dispute_resolution', 'resolve_dispute.php?id=1&booking_id=32', 0, '2026-05-29 14:06:53'),
(141, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your Japanese session on Saturday, May 30, 2026 at 5:00 PM.', 'meeting_link', 'join_meeting.php?booking_id=50&link=https%3A%2F%2Fmeet.google.com%2Fzko-xyzk-tgr', 1, '2026-05-29 14:31:28'),
(142, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your Japanese session on Saturday, June 27, 2026 at 5:00 PM.', 'meeting_link', 'join_meeting.php?booking_id=48&link=https%3A%2F%2Fmeet.google.com%2Fzko-xyzk-tgr', 1, '2026-05-29 14:49:46'),
(143, 2, 'New Learning Material', 'Feng X uploaded: Lesson 1', 'learning_materials.php?booking', NULL, 1, '2026-05-29 17:34:48'),
(144, 2, 'Dispute Resolved ✓', 'Great news! The tutor has resolved the issue for your Mandarin session.', 'dispute_resolved', 'booking_detail.php?id=32&resolved=1', 1, '2026-05-29 17:35:03'),
(145, 3, 'Dispute Resolved - Confirmation', 'You have successfully resolved the dispute for the Mandarin session.', 'dispute_resolved', 'tutor_booking_detail.php?id=32', 0, '2026-05-29 17:35:03'),
(146, 2, 'Dispute Resolved ✓', 'Great news! The tutor has resolved the issue for your Mandarin session.', 'dispute_resolved', 'booking_detail.php?id=32&resolved=1', 1, '2026-05-29 17:35:08'),
(147, 3, 'Dispute Resolved - Confirmation', 'You have successfully resolved the dispute for the Mandarin session.', 'dispute_resolved', 'tutor_booking_detail.php?id=32', 0, '2026-05-29 17:35:08'),
(148, 2, 'New Learning Material', 'Feng X uploaded: Japanese greetings', 'learning_materials.php?booking', NULL, 1, '2026-05-29 18:13:12'),
(149, 2, 'Booking Expired - No Response', 'Your Japanese session scheduled for Friday, May 29, 2026 at 4:00 PM has been automatically cancelled because the tutor did not respond before the booking date.', 'booking_expired', 'booking_detail.php?id=47', 1, '2026-05-30 00:01:01'),
(150, 5, 'Booking Auto-Cancelled', 'You did not respond to Sharon\'s Japanese session request for Friday, May 29, 2026 at 4:00 PM. It has been automatically cancelled.', 'booking_auto_cancelled', 'booking_detail.php?id=47', 0, '2026-05-30 00:01:01'),
(151, 4, 'Booking Expired - No Response', 'Your English session scheduled for Friday, May 29, 2026 at 6:00 AM has been automatically cancelled because the tutor did not respond before the booking date.', 'booking_expired', 'booking_detail.php?id=38', 1, '2026-05-30 00:01:13'),
(152, 10, 'Booking Auto-Cancelled', 'You did not respond to Sarah\'s English session request for Friday, May 29, 2026 at 6:00 AM. It has been automatically cancelled.', 'booking_auto_cancelled', 'booking_detail.php?id=38', 0, '2026-05-30 00:01:13'),
(153, 2, 'Progress updated', 'Your tutor has updated your progress for your English session on 13 May 2026', 'session_report', 'my_progress.php', 1, '2026-05-30 03:29:56'),
(154, 2, 'Progress updated', 'Your tutor has updated your progress for your English session on 12 May 2026', 'session_report', 'my_progress.php', 1, '2026-05-30 03:31:50'),
(155, 2, 'Progress updated', 'Your tutor has updated your progress for your English session on 12 May 2026', 'session_report', 'my_progress.php', 1, '2026-05-30 03:33:25'),
(156, 2, 'Progress updated', 'Your tutor has updated your progress for your English session on 12 May 2026', 'session_report', 'my_progress.php', 1, '2026-05-30 03:34:37'),
(157, 2, 'Progress updated', 'Your tutor has updated your progress for your English session on 12 May 2026', 'session_report', 'my_progress.php', 1, '2026-05-30 03:37:08'),
(158, 3, 'Session Starting Soon', 'Your Mandarin session with Sarah starts in 8 minutes at 2:00 PM', 'reminder', 'tutor_booking_detail.php?id=37', 0, '2026-05-30 13:51:53'),
(159, 4, 'Session Starting Soon', 'Your Mandarin session with Feng X starts in 8 minutes at 2:00 PM', 'reminder', 'booking_detail.php?id=37', 1, '2026-05-30 13:51:53'),
(160, 2, 'Session Completed — No Show', 'Your Malay session with Farah Nabila on 29 May 2026 at 9:00 AM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=22', 1, '2026-05-30 13:57:20'),
(161, 9, 'Session Completed — No Show', 'Your Malay session with Sharon on 29 May 2026 at 9:00 AM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=22', 0, '2026-05-30 13:57:20'),
(162, 3, 'Session Starting Soon', 'Your Mandarin session with Sharon starts in 14 minutes at 3:00 PM', 'reminder', 'tutor_booking_detail.php?id=32', 0, '2026-05-30 14:45:01'),
(163, 2, 'Session Starting Soon', 'Your Mandarin session with Feng X starts in 14 minutes at 3:00 PM', 'reminder', 'booking_detail.php?id=32', 1, '2026-05-30 14:45:02'),
(164, 8, 'Session Starting Soon', 'Your Korean session with Sharon starts in 14 minutes at 4:00 PM', 'reminder', 'tutor_booking_detail.php?id=20', 0, '2026-05-30 15:45:01'),
(165, 2, 'Session Starting Soon', 'Your Korean session with Kim Jisoo starts in 14 minutes at 4:00 PM', 'reminder', 'booking_detail.php?id=20', 1, '2026-05-30 15:45:01'),
(166, 3, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 14 minutes at 5:00 PM', 'reminder', 'tutor_booking_detail.php?id=50', 0, '2026-05-30 16:45:01'),
(167, 2, 'Session Starting Soon', 'Your Japanese session with Feng X starts in 14 minutes at 5:00 PM', 'reminder', 'booking_detail.php?id=50', 1, '2026-05-30 16:45:01'),
(168, 2, 'Cancellation Email Sent', 'An email notification about your cancelled session has been sent.', 'auto_cancelled_email', 'booking_status.php?id=34', 1, '2026-05-31 02:06:51'),
(169, 3, 'Student Confirmed', 'Your student Sharon has confirmed they attended the Japanese session.', 'session_confirmation', 'tutor_booking_detail.php?id=50', 0, '2026-05-31 15:49:07'),
(170, 2, 'Session Completed ✓', 'Your Mandarin session with Feng X on 30 May 2026 at 3:00 PM has been completed. Both you and your tutor attended. If you had any issues, please report within 7 days.', 'completed', 'booking_detail.php?id=32', 1, '2026-06-01 17:05:50'),
(171, 3, 'Session Completed — Payment Processing', 'Your Mandarin session with Sharon on 30 May 2026 at 3:00 PM has been completed. Both attended. Payment will be processed within 3–5 business days.', 'completed', 'tutor_booking_detail.php?id=32', 0, '2026-06-01 17:05:50'),
(172, 4, 'Session Completed — No Show', 'Your Mandarin session with Feng X on 30 May 2026 at 2:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=37', 1, '2026-06-01 17:06:21'),
(173, 3, 'Session Completed — No Show', 'Your Mandarin session with Sarah on 30 May 2026 at 2:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=37', 0, '2026-06-01 17:06:21'),
(174, 2, 'Session Completed — No Show', 'Your Japanese session with Feng X on 30 May 2026 at 5:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=50', 1, '2026-06-01 17:06:35'),
(175, 3, 'Session Completed — No Show', 'Your Japanese session with Sharon on 30 May 2026 at 5:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=50', 0, '2026-06-01 17:06:35'),
(176, 2, 'Session Completed — No Show', 'Your Korean session with Kim Jisoo on 30 May 2026 at 4:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=20', 1, '2026-06-01 17:06:45'),
(177, 8, 'Session Completed — No Show', 'Your Korean session with Sharon on 30 May 2026 at 4:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=20', 0, '2026-06-01 17:06:45'),
(178, 2, 'Booking Accepted!', 'Your booking for English on 30 Jun 2026 at 1:00 AM has been accepted by tutor Aina Yusuf. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=52', 1, '2026-06-03 00:38:40'),
(179, 2, 'Booking Accepted!', 'Your booking for Japanese on 04 Jun 2026 at 4:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=53', 1, '2026-06-03 04:18:17'),
(180, 2, 'Booking Accepted!', 'Your booking for Japanese on 11 Jun 2026 at 4:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=54', 1, '2026-06-03 04:18:26'),
(181, 2, 'Booking Accepted!', 'Your booking for Mandarin on 25 Jun 2026 at 6:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=55', 1, '2026-06-03 07:49:33'),
(182, 2, 'Booking Accepted!', 'Your booking for Japanese on 03 Jun 2026 at 9:00 AM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=51', 1, '2026-06-03 08:18:10'),
(183, 2, 'Progress updated', 'Your tutor has updated your progress for your Japanese session on 28 May 2026', 'session_report', 'my_progress.php', 1, '2026-06-03 08:20:26'),
(184, 10, 'Student Confirmed', 'Your student Sharon has confirmed they attended the English session.', 'session_confirmation', 'tutor_booking_detail.php?id=24', 0, '2026-06-03 08:58:20'),
(185, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your English session on Tuesday, June 30, 2026 at 1:00 AM.', 'meeting_link', 'join_meeting.php?booking_id=52&link=https%3A%2F%2Fmeet.google.com%2Fuev-betk-yup', 1, '2026-06-03 08:59:13'),
(186, 2, 'Session Cancelled', 'Your Japanese session on Wednesday, 03 June 2026 at 09:00 AM has been cancelled because payment was not received before the session. Please book a new session.', 'auto_cancelled', 'booking_status.php?id=51', 1, '2026-06-03 09:00:39'),
(187, 2, 'Cancellation Email Sent', 'An email notification about your cancelled session has been sent.', 'auto_cancelled_email', 'booking_status.php?id=51', 1, '2026-06-03 09:00:43'),
(188, 2, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 5:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=56', 1, '2026-06-03 09:05:35'),
(189, 2, 'Session Completed — No Show', 'Your English session with Feng X on 29 May 2026 at 6:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=34', 1, '2026-06-03 12:00:02'),
(190, 3, 'Session Completed — No Show', 'Your English session with Sharon on 29 May 2026 at 6:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=34', 0, '2026-06-03 12:00:02'),
(191, 2, 'Session Completed — No Show', 'Your English session with Aina Yusuf on 29 May 2026 at 12:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=24', 1, '2026-06-03 12:00:14'),
(192, 10, 'Session Completed — No Show', 'Your English session with Sharon on 29 May 2026 at 12:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=24', 0, '2026-06-03 12:00:14'),
(193, 2, 'Booking Accepted!', 'Your booking for English on 04 Jun 2026 at 11:00 AM has been accepted by tutor Daniel Lee. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=57', 1, '2026-06-03 12:02:48'),
(194, 6, 'Meeting Link Required', 'Your English session with Sharon on 04 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=57', 0, '2026-06-03 12:15:02'),
(195, 6, 'Meeting Link Required', 'Your English session with Sharon on 04 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=57', 0, '2026-06-03 12:15:02'),
(196, 6, 'Meeting Link Required', 'Your English session with Sharon on 04 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=57', 0, '2026-06-03 12:15:02'),
(197, 6, 'Meeting Link Required', 'Your English session with Sharon on 04 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=57', 0, '2026-06-03 12:15:02'),
(198, 6, 'Meeting Link Required', 'Your English session with Sharon on 04 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=57', 0, '2026-06-03 12:15:02'),
(199, 2, 'Booking Accepted!', 'Your booking for Japanese on 17 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=59', 1, '2026-06-03 19:46:56'),
(200, 2, 'New Assignment: Assignment 1', 'You have a new assignment: Assignment 1 (Due: 30 Jun 2026, 12:30 AM)', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 08:32:10'),
(201, 2, 'New Assignment: Assignment 1', 'You have a new assignment: Assignment 1', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 08:44:32'),
(202, 2, 'New Assignment: Assignment 1', 'You have a new assignment: Assignment 1 (Due: 30 Jun 2026, 12:49 PM)', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 08:46:09'),
(203, 2, 'New Assignment: Assignment 1', 'You have a new assignment: Assignment 1', 'assignment', 'my_assignments.php?booking_id=55', 1, '2026-06-04 08:56:02'),
(204, 2, 'New Assignment: Assignment 1', 'You have a new assignment: Assignment 1 (Due: 26 Jun 2026, 12:00 AM)', 'assignment', 'my_assignments.php?booking_id=55', 1, '2026-06-04 08:56:27'),
(205, 2, 'New Assignment: Assignment 1', 'You have a new assignment: Assignment 1 (Due: 26 Jun 2026, 12:00 AM)', 'assignment', 'my_assignments.php?booking_id=55', 1, '2026-06-04 09:01:46'),
(206, 2, 'New Assignment: ASSIGNMENT 1', 'You have a new assignment: ASSIGNMENT 1 (Due: 27 Jun 2026, 11:45 AM)', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 09:12:31'),
(207, 2, 'New Assignment: ASSIGNMENT 1', 'You have a new assignment: ASSIGNMENT 1 (Due: 16 Jun 2026, 12:30 AM)', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 09:25:08'),
(208, 3, 'New Assignment Submission', 'A student has submitted an assignment: ASSIGNMENT 1', 'submission', 'assignment_overview.php', 0, '2026-06-04 09:52:21'),
(209, 6, 'Session Starting Soon', 'Your English session with Sharon starts in 14 minutes at 11:00 AM', 'reminder', 'tutor_booking_detail.php?id=57', 0, '2026-06-04 10:45:16'),
(210, 2, 'Session Starting Soon', 'Your English session with Daniel Lee starts in 14 minutes at 11:00 AM', 'reminder', 'booking_detail.php?id=57', 1, '2026-06-04 10:45:16'),
(211, 3, 'Meeting Link Required', 'Your Japanese session with Sharon on 04 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=53', 0, '2026-06-04 11:15:03'),
(212, 2, 'New Assignment: ASSIGNMENT 1', 'You have a new assignment: ASSIGNMENT 1 (Due: 23 Jun 2026, 12:00 AM)', 'assignment', 'my_assignments.php?booking_id=55', 1, '2026-06-04 11:44:20'),
(213, 3, 'New Assignment Submission', 'A student has submitted an assignment: HOMEWORK 1', 'submission', 'assignment_overview.php', 0, '2026-06-04 11:52:11'),
(214, 2, 'New Assignment: HOMEWORK 1', 'You have a new assignment: HOMEWORK 1', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 15:31:12'),
(215, 3, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 14 minutes at 4:00 PM', 'reminder', 'tutor_booking_detail.php?id=53', 0, '2026-06-04 15:45:01'),
(216, 2, 'Session Starting Soon', 'Your Japanese session with Feng X starts in 14 minutes at 4:00 PM', 'reminder', 'booking_detail.php?id=53', 1, '2026-06-04 15:45:01'),
(217, 3, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 14 minutes at 4:00 PM', 'reminder', 'tutor_booking_detail.php?id=53', 0, '2026-06-04 15:45:01'),
(218, 3, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 14 minutes at 4:00 PM', 'reminder', 'tutor_booking_detail.php?id=53', 0, '2026-06-04 15:45:01'),
(219, 2, 'Session Starting Soon', 'Your Japanese session with Feng X starts in 14 minutes at 4:00 PM', 'reminder', 'booking_detail.php?id=53', 1, '2026-06-04 15:45:01'),
(220, 2, 'Session Starting Soon', 'Your Japanese session with Feng X starts in 14 minutes at 4:00 PM', 'reminder', 'booking_detail.php?id=53', 1, '2026-06-04 15:45:01'),
(221, 3, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 14 minutes at 4:00 PM', 'reminder', 'tutor_booking_detail.php?id=53', 0, '2026-06-04 15:45:01'),
(222, 2, 'Session Starting Soon', 'Your Japanese session with Feng X starts in 14 minutes at 4:00 PM', 'reminder', 'booking_detail.php?id=53', 1, '2026-06-04 15:45:01'),
(223, 3, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 14 minutes at 4:00 PM', 'reminder', 'tutor_booking_detail.php?id=53', 0, '2026-06-04 15:45:01'),
(224, 2, 'Session Starting Soon', 'Your Japanese session with Feng X starts in 14 minutes at 4:00 PM', 'reminder', 'booking_detail.php?id=53', 1, '2026-06-04 15:45:01'),
(225, 2, 'New Assignment: ac', 'You have a new assignment: ac (Due: 24 Jun 2026, 8:43 PM)', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 15:45:32'),
(226, 2, 'New Assignment: a', 'You have a new assignment: a', 'assignment', 'my_assignments.php?booking_id=56', 1, '2026-06-04 17:20:35'),
(227, 2, 'New Assignment: a', 'You have a new assignment: a', 'assignment', 'my_assignments.php?booking_id=48', 1, '2026-06-04 17:25:03'),
(228, 7, 'Session Starting Soon', 'Your Mandarin session with Sharon starts in 14 minutes at 8:00 PM', 'reminder', 'tutor_booking_detail.php?id=45', 0, '2026-06-04 19:45:02'),
(229, 7, 'Session Starting Soon', 'Your Mandarin session with Sharon starts in 14 minutes at 8:00 PM', 'reminder', 'tutor_booking_detail.php?id=45', 0, '2026-06-04 19:45:02'),
(230, 2, 'Session Starting Soon', 'Your Mandarin session with Alicia Wong starts in 14 minutes at 8:00 PM', 'reminder', 'booking_detail.php?id=45', 1, '2026-06-04 19:45:02'),
(231, 7, 'Session Starting Soon', 'Your Mandarin session with Sharon starts in 14 minutes at 8:00 PM', 'reminder', 'tutor_booking_detail.php?id=45', 0, '2026-06-04 19:45:02'),
(232, 2, 'Session Starting Soon', 'Your Mandarin session with Alicia Wong starts in 14 minutes at 8:00 PM', 'reminder', 'booking_detail.php?id=45', 1, '2026-06-04 19:45:02'),
(233, 7, 'Session Starting Soon', 'Your Mandarin session with Sharon starts in 14 minutes at 8:00 PM', 'reminder', 'tutor_booking_detail.php?id=45', 0, '2026-06-04 19:45:02'),
(234, 2, 'Session Starting Soon', 'Your Mandarin session with Alicia Wong starts in 14 minutes at 8:00 PM', 'reminder', 'booking_detail.php?id=45', 1, '2026-06-04 19:45:02'),
(235, 2, 'Session Starting Soon', 'Your Mandarin session with Alicia Wong starts in 14 minutes at 8:00 PM', 'reminder', 'booking_detail.php?id=45', 1, '2026-06-04 19:45:02'),
(236, 7, 'Session Starting Soon', 'Your Mandarin session with Sharon starts in 14 minutes at 8:00 PM', 'reminder', 'tutor_booking_detail.php?id=45', 0, '2026-06-04 19:45:02'),
(237, 2, 'Session Starting Soon', 'Your Mandarin session with Alicia Wong starts in 14 minutes at 8:00 PM', 'reminder', 'booking_detail.php?id=45', 1, '2026-06-04 19:45:02'),
(238, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 06 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-05 00:00:00'),
(239, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 06 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-05 00:00:01'),
(240, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 06 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-05 00:00:02'),
(241, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 06 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-05 00:00:02'),
(242, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 06 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-05 00:00:02'),
(243, 8, 'Meeting Link Required', 'Your Korean session with Sharon on 06 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-05 00:00:02'),
(244, 2, 'Session Completed — No Show', 'Your English session with Daniel Lee on 04 Jun 2026 at 11:00 AM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=57', 1, '2026-06-05 12:00:02'),
(245, 6, 'Session Completed — No Show', 'Your English session with Sharon on 04 Jun 2026 at 11:00 AM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=57', 0, '2026-06-05 12:00:02'),
(246, 8, 'Session Starting Soon', 'Your Korean session with Sharon starts in 14 minutes at 12:00 PM', 'reminder', 'tutor_booking_detail.php?id=19', 0, '2026-06-06 11:45:01'),
(247, 2, 'Session Starting Soon', 'Your Korean session with Kim Jisoo starts in 14 minutes at 12:00 PM', 'reminder', 'booking_detail.php?id=19', 1, '2026-06-06 11:45:01'),
(248, 4, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=61', 1, '2026-06-06 11:46:44'),
(249, 4, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=61', 1, '2026-06-06 11:46:48');
INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `link`, `is_read`, `created_at`) VALUES
(250, 4, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 1:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=60', 1, '2026-06-06 11:46:55'),
(251, 2, 'Session Completed — No Show', 'Your Japanese session with Feng X on 04 Jun 2026 at 4:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=53', 1, '2026-06-06 12:00:01'),
(252, 3, 'Session Completed — No Show', 'Your Japanese session with Sharon on 04 Jun 2026 at 4:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=53', 0, '2026-06-06 12:00:01'),
(253, 2, 'Session Completed — No Show', 'Your Mandarin session with Alicia Wong on 04 Jun 2026 at 8:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=45', 1, '2026-06-06 12:00:10'),
(254, 7, 'Session Completed — No Show', 'Your Mandarin session with Sharon on 04 Jun 2026 at 8:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=45', 0, '2026-06-06 12:00:10'),
(255, 2, 'Session Completed — No Show', 'Your English session with Aina Yusuf on 21 May 2026 at 1:00 AM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=2', 1, '2026-06-06 12:00:19'),
(256, 10, 'Session Completed — No Show', 'Your English session with Sharon on 21 May 2026 at 1:00 AM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=2', 0, '2026-06-06 12:00:19'),
(257, 4, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=64', 1, '2026-06-06 18:32:50'),
(258, 4, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=64', 1, '2026-06-06 18:32:56'),
(259, 4, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 1:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=63', 1, '2026-06-06 18:33:01'),
(260, 4, 'Booking Accepted!', 'Your booking for Japanese on 26 Jun 2026 at 10:00 AM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=66', 1, '2026-06-06 18:33:06'),
(261, 4, 'Booking Accepted!', 'Your booking for Japanese on 26 Jun 2026 at 11:00 AM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=65', 1, '2026-06-06 18:33:11'),
(262, 2, 'Serious Issue Reported - Under Review', 'Your report for the English session has been submitted. This is a serious issue and admin will review within 2-3 business days.', 'dispute', 'booking_detail.php?id=2', 1, '2026-06-07 02:01:26'),
(263, 10, 'Serious Issue Reported - Under Review', 'A student has reported a SERIOUS issue with your English session. Admin will review and contact you.', 'dispute', 'tutor_booking_detail.php?id=2', 0, '2026-06-07 02:01:26'),
(264, 2, 'Serious Issue Reported - Under Review', 'Your report for the English session has been submitted. This is a serious issue and admin will review within 2-3 business days.', 'dispute', 'booking_detail.php?id=57', 1, '2026-06-07 02:43:50'),
(265, 6, 'Serious Issue Reported - Under Review', 'A student has reported a SERIOUS issue with your English session. Admin will review and contact you.', 'dispute', 'tutor_booking_detail.php?id=57', 0, '2026-06-07 02:43:50'),
(266, 2, 'Issue Reported - Tutor Notified', 'Your issue has been reported. The tutor has been notified and will contact you to resolve it within 48 hours.', 'dispute', 'booking_detail.php?id=59', 1, '2026-06-07 02:47:49'),
(267, 5, 'Student Reported Issue - Please Resolve', 'A student has reported an issue with your Japanese session.\n\nIssue: Wrong materials\nMessage: YOU HAVE SUBMITTED WRONG MATERIALS PLEASE RESUBMIT BASED ON WHAT I PICK ON THE LANGUAGE\n\nPlease resolve this issue within 48 hours, otherwise it will be escalated to admin.', 'dispute_resolution', 'resolve_dispute.php?id=6&booking_id=59', 0, '2026-06-07 02:47:49'),
(268, 2, 'Dispute Resolved', 'Your dispute has been resolved. Refund of RM 47.00 processed. Receipt: RFD-20260607-000003', 'dispute', 'booking_detail.php?id=2&resolved=1', 1, '2026-06-07 06:26:50'),
(269, 10, 'Dispute Resolved', 'The dispute for session #2 has been resolved. Refund of RM 47.00 processed. Receipt: RFD-20260607-000003', 'dispute', 'tutor_booking_detail.php?id=2', 0, '2026-06-07 06:26:50'),
(270, 2, 'Dispute Resolved', 'Your dispute has been resolved. Refund of RM 50.00 processed. Receipt: RFD-20260607-000005', 'dispute', 'booking_detail.php?id=57&resolved=1', 1, '2026-06-07 06:48:26'),
(271, 6, 'Dispute Resolved', 'The dispute for session #57 has been resolved. Refund of RM 50.00 processed. Receipt: RFD-20260607-000005', 'dispute', 'tutor_booking_detail.php?id=57', 0, '2026-06-07 06:48:26'),
(272, 2, 'Session Completed — No Show', 'Your Korean session with Kim Jisoo on 06 Jun 2026 at 12:00 PM is completed. Neither you nor your tutor attended. Contact support to reschedule.', 'warning', 'booking_detail.php?id=19', 1, '2026-06-07 15:37:01'),
(273, 8, 'Session Completed — No Show', 'Your Korean session with Sharon on 06 Jun 2026 at 12:00 PM is completed. Neither party attended. No payment will be processed.', 'warning', 'tutor_booking_detail.php?id=19', 0, '2026-06-07 15:37:01'),
(274, 4, 'Dispute Resolved', 'Your dispute has been resolved. ', 'dispute', 'booking_detail.php?id=66&resolved=1', 1, '2026-06-08 12:13:00'),
(275, 5, 'Dispute Resolved', 'The dispute for session #66 has been resolved. ', 'dispute', 'tutor_booking_detail.php?id=66', 0, '2026-06-08 12:13:00'),
(276, 5, 'New Reschedule Request', 'Student has requested to reschedule a session from 25 Jun 2026, 12:00 PM to 18 Jun 2026, 11:00 AM', 'reschedule', NULL, 0, '2026-06-08 12:15:44'),
(277, 4, 'Session Report', 'Your tutor has submitted a session report for your Japanese session on 18 May 2026', 'session_report', 'my_progress.php', 1, '2026-06-08 12:22:12'),
(278, 4, 'Dispute Resolved', 'Your dispute has been resolved. Refund of RM 45.00 processed. Receipt: RFD-20260608-000007', 'dispute', 'booking_detail.php?id=65&resolved=1', 1, '2026-06-08 19:38:35'),
(279, 5, 'Dispute Resolved', 'The dispute for session #65 has been resolved. Refund of RM 45.00 processed. Receipt: RFD-20260608-000007', 'dispute', 'tutor_booking_detail.php?id=65', 0, '2026-06-08 19:38:35'),
(280, 2, 'Dispute Resolved', 'Your dispute has been resolved. Refund of RM 47.00 processed. Receipt: RFD-20260608-000004', 'dispute', 'booking_detail.php?id=2&resolved=1', 1, '2026-06-08 19:54:43'),
(281, 10, 'Dispute Resolved', 'The dispute for session #2 has been resolved. Refund of RM 47.00 processed. Receipt: RFD-20260608-000004', 'dispute', 'tutor_booking_detail.php?id=2', 0, '2026-06-08 19:54:43'),
(282, 2, 'Dispute Resolved', 'Your dispute has been resolved. Issue resolved between student and tutor.', 'dispute', 'booking_detail.php?id=32&resolved=1', 1, '2026-06-08 21:28:46'),
(283, 3, 'Dispute Resolved', 'The dispute for session #32 has been resolved. Issue resolved between student and tutor.', 'dispute', 'tutor_booking_detail.php?id=32', 0, '2026-06-08 21:28:46'),
(284, 4, 'Booking Accepted!', 'Your booking for English on 17 Jun 2026 at 10:00 AM has been accepted by tutor Aina Yusuf. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=67', 0, '2026-06-08 22:03:05'),
(285, 4, 'Booking Accepted!', 'Your booking for English on 23 Jun 2026 at 10:00 AM has been accepted by tutor Aina Yusuf. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=69', 0, '2026-06-08 22:03:13'),
(286, 4, 'Booking Accepted!', 'Your booking for English on 24 Jun 2026 at 10:00 AM has been accepted by tutor Aina Yusuf. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=68', 0, '2026-06-08 22:03:22'),
(287, 10, 'New Reschedule Request', 'Student has requested to reschedule a session from 30 Jun 2026, 1:00 AM to 17 Jun 2026, 11:00 AM', 'reschedule', NULL, 0, '2026-06-09 12:02:41'),
(288, 2, 'Reschedule Request Cancelled', 'You have cancelled your reschedule request for English session on 30 Jun 2026, 1:00 AM. Your original booking remains confirmed.', 'booking', 'booking_detail.php?id=52', 1, '2026-06-09 12:02:50'),
(289, 10, 'Reschedule Request Cancelled by Student', 'Student has cancelled their reschedule request for English session on 30 Jun 2026, 1:00 AM. The original booking remains confirmed.', 'booking', 'tutor_booking_detail.php?id=52', 0, '2026-06-09 12:02:50'),
(290, 5, 'New Reschedule Request', 'Student has requested to reschedule a session from 17 Jun 2026, 12:00 PM to 30 Jun 2026, 2:00 PM', 'reschedule', NULL, 0, '2026-06-09 12:08:26'),
(291, 3, 'New Reschedule Request', 'Student has requested to reschedule a session from 25 Jun 2026, 5:00 PM to 26 Jun 2026, 4:00 PM', 'reschedule', NULL, 0, '2026-06-09 12:08:42'),
(292, 4, 'Reschedule Request Cancelled', 'You have cancelled your reschedule request for Japanese session on 25 Jun 2026, 12:00 PM. Your original booking remains confirmed.', 'booking', 'booking_detail.php?id=64', 0, '2026-06-09 12:41:36'),
(293, 5, 'Reschedule Request Cancelled by Student', 'Student has cancelled their reschedule request for Japanese session on 25 Jun 2026, 12:00 PM. The original booking remains confirmed.', 'booking', 'tutor_booking_detail.php?id=64', 0, '2026-06-09 12:41:36'),
(294, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 26 Jun 2026 at 4:00 PM', 'reschedule', NULL, 1, '2026-06-09 14:12:35'),
(295, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your Japanese session on Thursday, June 11, 2026 at 4:00 PM.', 'meeting_link', 'join_meeting.php?booking_id=54&link=https%3A%2F%2Fmeet.google.com%2Fceb-jegw-gmj', 1, '2026-06-09 14:21:55'),
(296, 23, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=73', 0, '2026-06-12 17:29:58'),
(297, 27, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 5:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=79', 0, '2026-06-12 21:24:05'),
(298, 27, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=74', 0, '2026-06-12 21:24:17'),
(299, 27, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 1:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=75', 0, '2026-06-12 21:24:28'),
(300, 27, 'Booking Accepted!', 'Your booking for Japanese on 26 Jun 2026 at 1:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=76', 0, '2026-06-12 21:24:46'),
(301, 27, 'Booking Accepted!', 'Your booking for Japanese on 26 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=77', 0, '2026-06-12 21:24:55'),
(302, 27, 'Booking Accepted!', 'Your booking for Korean on 13 Jun 2026 at 10:00 AM has been accepted by tutor Kim Jisoo. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=80', 0, '2026-06-12 21:26:42'),
(303, 25, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 4:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=83', 0, '2026-06-12 21:53:36'),
(304, 25, 'Booking Accepted!', 'Your booking for Japanese on 15 Jun 2026 at 9:00 AM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=81', 0, '2026-06-12 21:56:14'),
(305, 27, 'Dispute Resolved', 'Your dispute has been resolved. Session rescheduled from 26 Jun 2026 at 1:00 PM to 26 Jun 2026 at 4:00 PM', 'dispute', 'booking_detail.php?id=76&resolved=1', 0, '2026-06-13 00:48:14'),
(306, 5, 'Dispute Resolved', 'The dispute for session #76 has been resolved. Session rescheduled from 26 Jun 2026 at 1:00 PM to 26 Jun 2026 at 4:00 PM', 'dispute', 'tutor_booking_detail.php?id=76', 0, '2026-06-13 00:48:14'),
(307, 27, 'Dispute Resolved', 'Your dispute has been resolved. Refund of RM 45.00 processed. Receipt: RFD-20260613-000012', 'dispute', 'booking_detail.php?id=77&resolved=1', 0, '2026-06-13 01:36:52'),
(308, 5, 'Dispute Resolved', 'The dispute for session #77 has been resolved. Refund of RM 45.00 processed. Receipt: RFD-20260613-000012', 'dispute', 'tutor_booking_detail.php?id=77', 0, '2026-06-13 01:36:52'),
(309, 27, 'Dispute Resolved', 'Your dispute has been resolved. Payment dispute rejected. Booking cancelled. Student must make correct payment.', 'dispute', 'booking_detail.php?id=79&resolved=1', 0, '2026-06-13 01:40:07'),
(310, 5, 'Dispute Resolved', 'The dispute for session #79 has been resolved. Payment dispute rejected. Booking cancelled. Student must make correct payment.', 'dispute', 'tutor_booking_detail.php?id=79', 0, '2026-06-13 01:40:07'),
(311, 20, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 4:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=87', 0, '2026-06-13 03:01:35'),
(312, 25, 'Booking Accepted!', 'Your booking for Japanese on 15 Jun 2026 at 10:00 AM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=82', 0, '2026-06-13 03:04:38'),
(313, 20, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 5:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=86', 0, '2026-06-13 03:04:50'),
(314, 20, 'Booking Accepted!', 'Your booking for Japanese on 24 Jun 2026 at 5:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=86', 0, '2026-06-13 03:04:57'),
(315, 20, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 11:00 AM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=84', 0, '2026-06-13 03:05:07'),
(316, 20, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 5:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=85', 0, '2026-06-13 03:05:17'),
(317, 20, 'Booking Accepted!', 'Your booking for Korean on 28 Jun 2026 at 1:00 PM has been accepted by tutor Kim Jisoo. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=88', 0, '2026-06-13 10:44:43'),
(318, 20, 'Booking Accepted!', 'Your booking for Korean on 28 Jun 2026 at 2:00 PM has been accepted by tutor Kim Jisoo. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=89', 0, '2026-06-13 10:44:51'),
(319, 20, 'Booking Accepted!', 'Your booking for Japanese on 16 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=90', 0, '2026-06-13 10:47:11'),
(320, 20, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 2:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=91', 0, '2026-06-13 11:36:05'),
(321, 20, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 3:00 PM has been accepted by tutor Feng X. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=92', 0, '2026-06-13 11:36:17'),
(322, 20, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 4:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=94', 0, '2026-06-13 11:57:59'),
(323, 20, 'Booking Accepted!', 'Your booking for Japanese on 25 Jun 2026 at 5:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=93', 0, '2026-06-13 11:58:08'),
(324, 20, 'Booking Accepted!', 'Your booking for Japanese on 30 Jun 2026 at 12:00 PM has been accepted by tutor Haruka Tan. Please proceed to payment.', 'booking', 'payment_form.php?booking_id=95', 0, '2026-06-13 11:58:16'),
(325, 8, 'Meeting Link Required', 'Your Korean session with James Wong on 13 Jun 2026 needs a meeting link. Please add it before the session.', 'reminder', 'tutor_booking_detail.php?id=80', 0, '2026-06-13 12:53:37'),
(326, 5, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 6 minutes at 1:00 PM', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-06-13 12:53:42'),
(327, 2, 'Session Starting Soon', 'Your Japanese session with Haruka Tan starts in 6 minutes at 1:00 PM', 'reminder', 'booking_detail.php?id=1', 0, '2026-06-13 12:53:42'),
(328, 5, 'Session Starting Soon', 'Your Japanese session with Sharon starts in 6 minutes at 1:00 PM', 'reminder', 'tutor_booking_detail.php?id=1', 0, '2026-06-13 12:53:46'),
(329, 2, 'Session Starting Soon', 'Your Japanese session with Haruka Tan starts in 6 minutes at 1:00 PM', 'reminder', 'booking_detail.php?id=1', 1, '2026-06-13 12:53:46');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `email`, `token`, `created_at`) VALUES
(4, 'morasharon790@gmail.com', '475e3b7938f7e6703fe228ab9de7488f80a8a54f96583cc9a6a9839720f6e028', '2026-05-10 10:48:44'),
(7, 'kay@gmail.com', '5e2c3e432f639a70e7e9c1ae77ceec27231ab35b7854c94e8d4b5e1a3da6ee9c', '2026-05-14 03:59:48'),
(9, 'morasharon790@gmail.com', '63b33dc82d410da0408af3132e1069548fb4a975722f4001d06fbbe2dcb702b2', '2026-05-25 03:04:13');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `tutor_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `actual_paid_amount` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `status` enum('pending','verified','rejected','disputed','failed','refunded') NOT NULL DEFAULT 'pending',
  `refund_status` enum('none','pending','completed') DEFAULT 'none',
  `receipt_number` varchar(20) DEFAULT NULL,
  `refund_receipt_number` varchar(50) DEFAULT NULL,
  `receipt_url` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `rejection_type` enum('wrong_amount','invalid_proof','unrelated_proof','other','underpaid_bulk') DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL,
  `disputed_at` datetime DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  `original_amount` decimal(10,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `promo_code` varchar(50) DEFAULT NULL,
  `platform_subsidy` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `booking_id`, `student_id`, `tutor_id`, `amount`, `actual_paid_amount`, `payment_method`, `status`, `refund_status`, `receipt_number`, `refund_receipt_number`, `receipt_url`, `notes`, `rejection_type`, `proof_image`, `created_at`, `verified_at`, `disputed_at`, `refund_processed_at`, `original_amount`, `discount_amount`, `promo_code`, `platform_subsidy`) VALUES
(1, 2, 2, 10, 47.00, NULL, 'online_banking', 'verified', 'completed', 'RCP-20260606-000001', 'RFD-20260608-000004', NULL, '', NULL, NULL, '2026-05-09 18:59:26', '2026-06-06 01:43:25', NULL, '2026-06-08 19:54:30', NULL, 0.00, NULL, 0.00),
(2, 1, 2, 5, 45.00, NULL, 'stripe', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-10 12:21:22', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(3, 20, 2, 8, 46.00, NULL, 'stripe', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-17 16:30:37', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(4, 24, 2, 10, 47.00, NULL, 'online_banking', 'verified', 'none', 'RCP-20260603-000004', NULL, NULL, '', NULL, 'proof_24_1779042604.png', '2026-05-17 18:30:04', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(5, 30, 2, 3, 50.00, NULL, 'stripe', 'verified', 'none', 'RCP-2026-087463', NULL, NULL, NULL, NULL, NULL, '2026-05-21 04:14:00', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(6, 28, 2, 3, 50.00, NULL, 'stripe', 'verified', 'none', 'pi_3TZOVlAjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKNGcutAGMgaijAHY6uI6LBYhhT2HOkmpzEHms_R0LMaagn4e0NLapRmjdVOuhc72hOoUXOw6IRPyD4bV', NULL, NULL, NULL, '2026-05-21 04:47:13', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(7, 37, 4, 3, 50.00, NULL, 'stripe', 'verified', 'none', 'pi_3TaGFkAjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKMjqxtAGMgbS5KKDl5E6LBapz65LC1iIk5EJl8Fo_DsCS2_6ViuPvvKzNBqc4PlPYYY9oqFHunWBRavY', NULL, NULL, NULL, '2026-05-23 14:10:15', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(16, 35, 2, 3, 50.00, NULL, 'stripe', 'verified', 'none', 'pi_3TbHibAjFaJboEti0', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKMPc1dAGMgZVCB8e5_46LBZhmCZDBM-9G0F7Qte9g_DZMrcQZ8K2yl9bZr1jg-4F2IAU6Jd_PHbNW60c', NULL, NULL, NULL, '2026-05-26 09:56:19', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(17, 32, 2, 3, 50.00, NULL, 'stripe', 'verified', 'none', 'pi_3TbLF9AjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKLLG1tAGMgb5PLDIsH06LBaKGEtxQrw_Rp_slkbt0iB74mIr98LBQ7AQxz39CuARarjDon7YOihuoKlc', NULL, NULL, NULL, '2026-05-26 13:42:10', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(18, 33, 2, 3, 50.00, NULL, 'stripe', 'verified', 'none', 'pi_3TbLF9AjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKLLG1tAGMgb5PLDIsH06LBaKGEtxQrw_Rp_slkbt0iB74mIr98LBQ7AQxz39CuARarjDon7YOihuoKlc', NULL, NULL, NULL, '2026-05-26 13:42:10', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(19, 34, 2, 3, 50.00, NULL, 'online_banking', 'verified', 'none', 'RCP-20260603-000019', NULL, NULL, '', NULL, 'proof_1779803019_4413.png', '2026-05-26 13:43:39', '2026-06-03 05:28:40', NULL, NULL, NULL, 0.00, NULL, 0.00),
(20, 9, 2, 3, 50.00, NULL, 'online_banking', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-28 06:42:30', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(21, 10, 2, 3, 50.00, NULL, 'online_banking', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-28 06:42:30', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(22, 11, 2, 3, 50.00, NULL, 'online_banking', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-28 06:42:30', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(23, 12, 2, 3, 50.00, NULL, 'online_banking', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-28 06:42:30', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(24, 13, 2, 3, 50.00, NULL, 'online_banking', 'verified', 'none', NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-28 06:42:30', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(27, 44, 2, 5, 45.00, NULL, 'stripe', 'verified', 'none', 'pi_3Tc3g9AjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKOn84NAGMgYhZpPHX9E6LBZJx2-O9UzqepQ1Hf_iV1vfWjTmkTu_FOE97_CguX6UGcVggEzdyVUUp3bD', NULL, NULL, NULL, '2026-05-28 13:08:57', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(28, 19, 2, 8, 46.00, NULL, 'stripe', 'verified', 'none', 'pi_3Tc4bTAjFaJboEti0', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKMuY4dAGMgaSMPqwVS86LBbid_JNuemPhIS7O5gpETfacK4GiIpS6Ywx4oeFLiLmXfiJRzxyC2nX3iux', NULL, NULL, NULL, '2026-05-28 14:08:12', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(29, 45, 2, 7, 48.00, NULL, 'stripe', 'verified', 'none', 'pi_3Tc4bTAjFaJboEti0', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKMuY4dAGMgaSMPqwVS86LBbid_JNuemPhIS7O5gpETfacK4GiIpS6Ywx4oeFLiLmXfiJRzxyC2nX3iux', NULL, NULL, NULL, '2026-05-28 14:08:12', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(30, 48, 2, 3, 60.00, NULL, 'stripe', 'verified', 'none', 'pi_3Tc5tLAjFaJboEti0', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKKO_4dAGMgZo9EJ6yKw6LBYHisMY3--v5--3xpGJdZpmL8YzEv8vaC65EXpQGTxmZ7jui0MhqPD9y-QO', NULL, NULL, NULL, '2026-05-28 15:30:43', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(31, 50, 2, 3, 60.00, NULL, 'stripe', 'verified', 'none', 'pi_3Tc647AjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKL_E4dAGMgYShya3Ee06LBYCLsN_9AS8yxALjpNV3fqjGoSACT7yfexK9rkqoBzZJ4u2NhQqDO5CebFx', NULL, NULL, NULL, '2026-05-28 15:41:51', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(32, 52, 2, 10, 47.00, NULL, 'duitnow', 'verified', 'none', 'RCP-20260603-000032', NULL, NULL, '', NULL, 'proof_1780418373_6423.png', '2026-06-02 16:39:33', '2026-06-03 05:53:59', NULL, NULL, NULL, 0.00, NULL, 0.00),
(33, 53, 2, 3, 60.00, NULL, 'online_banking', 'verified', 'none', 'RCP-20260604-000033', NULL, NULL, '', NULL, 'proof_1780431576_6837.png', '2026-06-02 20:19:36', '2026-06-04 11:11:20', NULL, NULL, NULL, 0.00, NULL, 0.00),
(34, 54, 2, 3, 13.00, 47.00, 'stripe', '', 'none', 'pi_3Tf3mDAjFaJboEti0', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKILTjNEGMgZo6WeTvpg6LBZG73v3DAs-e-C13-9PImdp2HYNmivcXpJWZ5ANfUHm4VX1bnbjpF3uk6vc', 'Wrong amount paid.  | Partial payment completed on 2026-06-06 03:54:39', 'wrong_amount', 'proof_1780431576_6837.png', '2026-06-05 19:54:39', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(35, 55, 2, 3, 60.00, NULL, 'online_banking', 'verified', 'none', 'RCP-20260603-000035', NULL, NULL, '', NULL, 'proof_1780444202_7843.png', '2026-06-02 23:50:02', '2026-06-03 08:05:27', NULL, NULL, NULL, 0.00, NULL, 0.00),
(36, 56, 2, 3, 60.00, NULL, 'stripe', 'verified', 'none', 'pi_3Te3GiAjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKLD8_dAGMgbHAdAwRkM6LBYHDzvdn9FJ_bdrSmdypBTMC8BzaUZN7DfY7zP9JkuhJGzDikeNiGyzwyiV', NULL, NULL, NULL, '2026-06-03 01:06:54', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(37, 57, 2, 6, 50.00, NULL, 'stripe', 'verified', 'completed', 'pi_3Te64MAjFaJboEti1', 'RFD-20260607-000005', 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKL_Q_tAGMgazUmIrLO86LBYdgJQcogrMU_JZ8EWXSJDf2o1qndNu2khrQX9_quaK8zh6iRe20i0LYSDN', NULL, NULL, NULL, '2026-06-03 04:06:23', NULL, NULL, '2026-06-07 06:48:15', NULL, 0.00, NULL, 0.00),
(38, 59, 2, 5, 45.00, NULL, 'stripe', 'verified', 'none', 'pi_3TeDTWAjFaJboEti0', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKPWugNEGMgZiKQUPc1o6LBagyd71p2sqFuklr7XYZbwYeFaWRsQ7xMvDVMcaaWW-iI1Zd9ku09k64zxe', NULL, NULL, NULL, '2026-06-03 12:00:53', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(52, 68, 4, 10, 47.00, NULL, 'duitnow', 'verified', 'none', 'RCP-20260612-000052', NULL, NULL, '', NULL, 'proof_1781254008_3049.png', '2026-06-12 08:46:48', '2026-06-12 21:46:21', NULL, NULL, NULL, 0.00, NULL, 0.00),
(53, 69, 4, 10, 47.00, NULL, 'duitnow', 'verified', 'none', 'RCP-20260612-000053', NULL, NULL, '', NULL, 'proof_1781254008_3049.png', '2026-06-12 08:46:48', '2026-06-12 21:46:25', NULL, NULL, NULL, 0.00, NULL, 0.00),
(54, 67, 4, 10, 47.00, 10.00, 'duitnow', 'rejected', 'none', 'RCP-2026-034827', NULL, NULL, 'Part of payment. Paid: RM 10, Remaining: RM 37', 'wrong_amount', 'proof_1781254043_2145.png', '2026-06-12 08:47:23', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(55, 67, 4, 10, 37.00, 10.00, 'duitnow', 'verified', 'none', 'RCP-20260612-000055', NULL, NULL, 'Partial payment for original payment #54. Remaining amount paid: RM 37.00. ', NULL, 'proof_1781255067_2112.pdf', '2026-06-12 09:04:27', '2026-06-12 21:43:06', NULL, NULL, NULL, 0.00, NULL, 0.00),
(56, 73, 23, 5, 45.00, 40.00, 'duitnow', 'rejected', 'none', 'RCP-2026-037642', NULL, NULL, 'Part of payment. Paid: RM 40, Remaining: RM 5', 'wrong_amount', 'proof_1781256640_1983.png', '2026-06-12 09:30:40', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(57, 73, 23, 5, 5.00, 47.00, 'online_banking', 'verified', 'completed', 'RCP-2026-086271', 'RFD-20260612-000057', NULL, 'Partial payment for original payment #56. Remaining amount paid: RM 5.00.  [OVERPAID: RM 42.00]\n[REFUND BANK DETAILS: MAYBANK - 11223344 - OLIVIA submitted on 2026-06-12 18:23:26]\n[REFUNDED: RM 42.00 on 2026-06-12 18:23:43] Refund receipt: RFD-20260612-000057', NULL, 'proof_1781257706_5505.png', '2026-06-12 09:48:26', '2026-06-12 18:22:38', NULL, '2026-06-12 18:23:43', NULL, 0.00, NULL, 0.00),
(58, 79, 27, 5, 45.00, NULL, 'duitnow', 'rejected', 'none', 'RCP-2026-098972', NULL, NULL, 'INVALID PROOF. IF YOU HAVE TRANSFERED PLEASE PRESS MONEY ALREADY DEDUCTED BUTTON TO RESEND A NEW PAYMENT PROOF', 'invalid_proof', 'proof_1781270900_7537.png', '2026-06-12 13:28:20', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(59, 80, 27, 8, 46.00, NULL, 'duitnow', 'verified', 'none', 'RCP-20260612-000059', NULL, NULL, '', NULL, 'proof_1781270900_7537.png', '2026-06-12 13:28:20', '2026-06-12 21:45:55', NULL, NULL, NULL, 0.00, NULL, 0.00),
(60, 83, 25, 3, 60.00, NULL, 'duitnow', 'verified', 'none', 'RCP-20260612-000060', NULL, NULL, '', NULL, 'proof_1781272632_2718.png', '2026-06-12 13:57:12', '2026-06-12 21:57:43', NULL, NULL, NULL, 0.00, NULL, 0.00),
(61, 81, 25, 5, 45.00, NULL, 'duitnow', 'verified', 'none', 'RCP-20260612-000061', NULL, NULL, '', NULL, 'proof_1781272632_2718.png', '2026-06-12 13:57:12', '2026-06-12 21:57:47', NULL, NULL, NULL, 0.00, NULL, 0.00),
(62, 76, 27, 5, 45.00, NULL, 'online_banking', 'disputed', 'none', 'RCP-2026-003658', NULL, NULL, 'THE PHOTO IS UNCLEAR.PLEASE PRESS THE MONEY ALREADY DEDUCTED BUTTON TO RESUBMIT A VALID PROOF FOR PROPER SOLUTION', 'unrelated_proof', 'proof_1781276219_7309.png', '2026-06-12 14:56:59', NULL, '2026-06-13 00:45:42', NULL, NULL, 0.00, NULL, 0.00),
(63, 77, 27, 5, 45.00, NULL, 'online_banking', 'disputed', 'completed', 'RCP-2026-032360', 'RFD-20260613-000012', NULL, 'THE PHOTO IS UNCLEAR. IF YOU HAVE ANY PROBLEM CAN PRESS THE MONEY ALREADY DEDUCTED TO ASK FOR SOLUTION', 'invalid_proof', 'proof_1781276248_6131.png', '2026-06-12 14:57:28', NULL, '2026-06-12 23:26:30', '2026-06-13 01:36:40', NULL, 0.00, NULL, 0.00),
(64, 75, 27, 5, 45.00, 100.00, 'online_banking', 'verified', 'completed', 'RCP-2026-090239', 'RFD-20260613-000064', NULL, ' [OVERPAID: RM 55.00]\n[REFUND BANK DETAILS: MAYBANK - 11223344 - OLIVIA submitted on 2026-06-13 01:47:39]\n[REFUNDED: RM 55.00 on 2026-06-13 01:47:48] Refund receipt: RFD-20260613-000064', NULL, 'proof_1781286353_3917.png', '2026-06-12 17:45:53', '2026-06-13 01:46:51', NULL, '2026-06-13 01:47:48', NULL, 0.00, NULL, 0.00),
(75, 84, 20, 5, 45.00, NULL, 'duitnow', 'rejected', 'none', 'RCP-2026-064423', NULL, NULL, 'PLEASE PRESS MONEY ALREADY DEDUCTED BUTTON TO RESUBMIT YOUR PROVE', 'invalid_proof', 'proof_1781292294_3913.png', '2026-06-12 19:24:54', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(76, 85, 20, 5, 45.00, NULL, 'duitnow', 'rejected', 'none', 'RCP-2026-064423', NULL, NULL, 'PLEASE PRESS MONEY ALREADY DEDUCTED BUTTON TO RESUBMIT YOUR PROVE', 'invalid_proof', 'proof_1781292294_3913.png', '2026-06-12 19:24:54', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(77, 86, 20, 5, 45.00, NULL, '', 'pending', 'none', '', NULL, NULL, '', '', '', '2026-06-12 19:25:26', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(78, 87, 20, 5, 45.00, NULL, 'duitnow', 'rejected', 'none', 'RCP-2026-095495', NULL, NULL, 'MONEY ALREADY DEDUCTED BUTTON', 'unrelated_proof', 'proof_1781292326_4389.png', '2026-06-12 19:25:26', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(79, 89, 20, 8, 46.00, 50.55, 'online_banking', 'verified', 'completed', 'RCP-2026-088861', 'BULK-1781320337024-79', NULL, ' [OVERPAID: RM 54.00]\n[REFUND BANK DETAILS: MAYBANK - 11223344 - OLIVIA submitted on 2026-06-13 11:11:58]\n[REFUNDED: RM 4.55 on 2026-06-13 11:12:17] Refund receipt: BULK-1781320337024-79', NULL, 'proof_1781318871_9102.png', '2026-06-13 02:47:51', '2026-06-13 10:49:09', NULL, '2026-06-13 11:12:17', NULL, 0.00, NULL, 0.00),
(80, 90, 20, 5, 45.00, 49.45, 'online_banking', 'verified', 'completed', 'RCP-2026-088861', 'BULK-1781320337034-80', NULL, ' [OVERPAID: RM 55.00]\n[REFUNDED: RM 4.45 on 2026-06-13 11:12:23] Refund receipt: BULK-1781320337034-80', NULL, 'proof_1781318871_9102.png', '2026-06-13 02:47:51', '2026-06-13 10:49:12', NULL, '2026-06-13 11:12:23', NULL, 0.00, NULL, 0.00),
(81, 88, 20, 8, 46.00, 47.74, 'online_banking', 'verified', 'completed', 'RCP-2026-035451', 'RFD-1781322710395-81', NULL, ' [OVERPAID: RM 1.74]\n[REFUNDED: RM 1.74 on 2026-06-13 11:51:50] Refund receipt: RFD-1781322710395-81', NULL, 'proof_1781321820_4818.png', '2026-06-13 03:37:00', '2026-06-13 11:37:56', NULL, '2026-06-13 11:51:50', NULL, 0.00, NULL, 0.00),
(82, 92, 20, 3, 60.00, 62.26, 'online_banking', 'verified', 'completed', 'RCP-2026-035451', 'RFD-1781322710397-82', NULL, ' [OVERPAID: RM 2.26]\n[REFUND BANK DETAILS: MAYBANK - 11223344 - james submitted on 2026-06-13 11:41:39]\n[REFUNDED: RM 2.26 on 2026-06-13 11:51:57] Refund receipt: RFD-1781322710397-82', NULL, 'proof_1781321820_4818.png', '2026-06-13 03:37:00', '2026-06-13 11:38:01', NULL, '2026-06-13 11:51:57', NULL, 0.00, NULL, 0.00),
(83, 93, 20, 5, 45.00, 40.00, 'online_banking', 'rejected', 'none', 'RCP-2026-034010', NULL, NULL, 'Part of bulk payment...', 'underpaid_bulk', 'proof_1781323138_2423.png', '2026-06-13 03:58:58', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(84, 95, 20, 5, 45.00, 45.00, 'online_banking', 'verified', 'none', 'pi_3ThimDAjFaJboEti1', NULL, 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKOits9EGMgYSmuRZhTI6LBY69E4qnqaVyC5bCjIgrtBvXITgt6Cr7xoatMsTX9xFyoDP81LRlMXQoHIU', 'Part of bulk payment... | Remaining amount (RM 5.00) paid via Stripe on 2026-06-13 12:04:55', 'underpaid_bulk', 'proof_1781323138_2423.png', '2026-06-13 03:58:59', '2026-06-13 12:04:55', NULL, NULL, NULL, 0.00, NULL, 0.00),
(87, 91, 20, 3, 60.00, 57.14, 'online_banking', 'rejected', 'none', 'RCP-2026-030671', NULL, NULL, ' [UNDERPAID: Paid RM 57.14, Remaining: RM 2.86]', 'underpaid_bulk', 'proof_1781324988_8686.png', '2026-06-13 04:29:48', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00),
(88, 94, 20, 5, 45.00, 42.86, 'online_banking', 'rejected', 'none', 'RCP-2026-030671', NULL, NULL, ' [UNDERPAID: Paid RM 42.86, Remaining: RM 2.14]', 'underpaid_bulk', 'proof_1781324988_8686.png', '2026-06-13 04:29:48', NULL, NULL, NULL, NULL, 0.00, NULL, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `payout_requests`
--

CREATE TABLE `payout_requests` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_account_name` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','completed','rejected') DEFAULT 'pending',
  `transaction_reference` varchar(100) DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payout_requests`
--

INSERT INTO `payout_requests` (`id`, `tutor_id`, `amount`, `bank_account_id`, `bank_name`, `bank_account_number`, `bank_account_name`, `status`, `transaction_reference`, `requested_at`, `processed_at`, `processed_by`, `admin_notes`, `completed_at`) VALUES
(1, 3, 280.00, 7, 'CIMB BANK', '1122334455', 'FENG XI', 'completed', '', '2026-05-30 17:28:43', '2026-06-05 15:01:09', 1, '\r\n\n[Completed on 2026-06-05 15:12:54 by Ali]\n', '2026-06-05 15:12:54');

-- --------------------------------------------------------

--
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('percentage','fixed') DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) DEFAULT 0,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `promotions`
--

INSERT INTO `promotions` (`id`, `title`, `description`, `discount_type`, `discount_value`, `code`, `valid_from`, `valid_to`, `usage_limit`, `used_count`, `is_active`, `created_at`) VALUES
(1, '🎉 First Session Special!', 'Get 20% OFF your first language session on Kyoshi', 'percentage', 20.00, 'FIRST20', '2026-06-08', '2027-06-08', 1000, 0, 1, '2026-06-08 15:12:52');

-- --------------------------------------------------------

--
-- Table structure for table `ratings`
--

CREATE TABLE `ratings` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ratings`
--

INSERT INTO `ratings` (`id`, `booking_id`, `student_id`, `tutor_id`, `rating`, `comment`, `is_anonymous`, `created_at`) VALUES
(1, 3, 2, 3, 4, 'Good Mandarin class, very patient teacher!', 0, '2026-05-09 07:25:23'),
(2, 4, 2, 6, 5, 'Daniel explains English grammar really well. Highly recommend!', 0, '2026-05-09 07:25:23'),
(8, 11, 2, 3, 5, 'Good class', 1, '2026-05-17 08:31:39'),
(9, 13, 2, 3, 3, '', 1, '2026-05-17 09:08:42'),
(11, 12, 2, 3, 5, 'Good class', 1, '2026-05-18 04:08:58'),
(12, 28, 2, 3, 5, 'Good explanation as usual', 0, '2026-05-28 11:30:41'),
(13, 21, 2, 9, 4, '', 0, '2026-05-28 11:37:34'),
(15, 53, 2, 3, 5, 'Good class', 1, '2026-06-09 04:15:36'),
(16, 45, 2, 7, 3, 'It shows unprofessional', 0, '2026-06-09 04:16:22'),
(17, 24, 2, 10, 5, 'By far good. Will join more class of yours', 0, '2026-06-09 04:16:40');

-- --------------------------------------------------------

--
-- Table structure for table `reschedule_requests`
--

CREATE TABLE `reschedule_requests` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `old_date` date NOT NULL,
  `old_time` time NOT NULL,
  `new_date` date NOT NULL,
  `new_time` time NOT NULL,
  `language` varchar(100) DEFAULT NULL,
  `learning_mode` enum('online','face_to_face') NOT NULL,
  `focus` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `meeting_location` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reject_reason` text DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reschedule_requests`
--

INSERT INTO `reschedule_requests` (`id`, `booking_id`, `student_id`, `tutor_id`, `old_date`, `old_time`, `new_date`, `new_time`, `language`, `learning_mode`, `focus`, `notes`, `meeting_location`, `status`, `created_at`, `updated_at`, `reject_reason`, `responded_at`) VALUES
(1, 1, 2, 5, '2026-05-28', '12:00:00', '2026-05-13', '16:00:00', 'Japanese', 'online', 'Writing', '', NULL, 'rejected', '2026-05-10 15:00:49', '2026-05-27 16:00:04', 'Auto-rejected: Tutor did not respond before the original booking date', '2026-05-28 00:00:04'),
(2, 17, 2, 3, '2026-05-28', '12:00:00', '2026-05-27', '14:00:00', 'Mandarin', 'online', 'Speaking', '', NULL, 'approved', '2026-05-17 07:05:15', '2026-05-23 11:34:17', NULL, '2026-05-23 19:34:17'),
(6, 20, 2, 8, '2026-06-13', '12:00:00', '2026-05-30', '14:00:00', 'Korean', 'online', 'Listening, Reading', '', NULL, '', '2026-05-19 08:57:09', '2026-05-26 07:40:42', 'Student cancelled the reschedule request', '2026-05-26 15:40:42'),
(7, 37, 4, 3, '2026-05-30', '13:00:00', '2026-05-30', '14:00:00', 'Mandarin', 'online', 'Writing', '', '', 'approved', '2026-05-23 14:33:10', '2026-05-23 14:36:55', NULL, '2026-05-23 22:36:55'),
(8, 22, 2, 9, '0000-00-00', '00:00:00', '2026-05-26', '09:00:00', 'Malay', 'online', NULL, NULL, NULL, 'rejected', '2026-05-26 03:42:58', '2026-05-29 05:03:12', 'Auto-rejected: Tutor did not respond before the original booking date', '2026-05-29 13:03:12'),
(10, 33, 2, 3, '2026-05-30', '18:00:00', '2026-06-15', '11:00:00', 'Mandarin', 'online', 'Speaking', '', '', 'approved', '2026-05-28 11:55:33', '2026-05-28 15:17:11', NULL, '2026-05-28 23:17:11'),
(12, 20, 2, 8, '2026-06-13', '12:00:00', '2026-05-30', '16:00:00', 'Korean', 'online', 'Speaking, Listening', '', '', 'approved', '2026-05-28 13:10:04', '2026-05-28 13:59:14', NULL, '2026-05-28 21:59:14'),
(13, 32, 2, 3, '2026-05-30', '15:00:00', '2026-05-30', '17:00:00', 'Mandarin', 'online', 'Speaking', '', '', 'rejected', '2026-05-28 13:39:50', '2026-05-28 15:20:45', 'Time slot unavailable', '2026-05-28 23:20:45'),
(14, 48, 2, 3, '2026-05-30', '17:00:00', '2026-06-27', '17:00:00', 'Japanese', 'online', 'Speaking', '', '', 'approved', '2026-05-28 15:30:56', '2026-05-28 15:38:12', NULL, '2026-05-28 23:38:12'),
(15, 50, 2, 3, '2026-05-30', '17:00:00', '2026-05-29', '17:00:00', 'Japanese', 'online', 'Speaking', '', '', 'rejected', '2026-05-28 15:42:49', '2026-05-28 20:49:12', 'Time slot not available', '2026-05-29 04:49:12'),
(16, 50, 2, 3, '2026-05-30', '17:00:00', '2026-06-25', '17:00:00', 'Japanese', 'online', 'Speaking', '', '', 'rejected', '2026-05-28 21:20:18', '2026-05-28 21:21:04', 'Not available', '2026-05-29 05:21:04'),
(19, 59, 2, 5, '2026-06-17', '12:00:00', '2026-06-30', '14:00:00', 'Japanese', 'face_to_face', 'Reading', '', '', 'pending', '2026-06-09 04:08:26', '2026-06-09 04:08:26', NULL, NULL),
(20, 56, 2, 3, '2026-06-25', '17:00:00', '2026-06-26', '16:00:00', 'Japanese', 'face_to_face', 'Speaking', '', '', 'approved', '2026-06-09 04:08:42', '2026-06-09 06:12:35', NULL, '2026-06-09 14:12:35');

-- --------------------------------------------------------

--
-- Table structure for table `session_completion`
--

CREATE TABLE `session_completion` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `tutor_confirmed` tinyint(1) DEFAULT 0,
  `student_confirmed` tinyint(1) DEFAULT 0,
  `tutor_confirmed_at` datetime DEFAULT NULL,
  `student_confirmed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `dispute_reason` text DEFAULT NULL,
  `attendance_manually_set` tinyint(4) DEFAULT 0,
  `no_show_type` varchar(20) DEFAULT NULL,
  `status` enum('pending','completed','disputed') DEFAULT 'pending',
  `auto_completed` tinyint(1) DEFAULT 0,
  `tutor_proof_image` varchar(255) DEFAULT NULL,
  `disputed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `session_completion`
--

INSERT INTO `session_completion` (`id`, `booking_id`, `tutor_confirmed`, `student_confirmed`, `tutor_confirmed_at`, `student_confirmed_at`, `completed_at`, `dispute_reason`, `attendance_manually_set`, `no_show_type`, `status`, `auto_completed`, `tutor_proof_image`, `disputed_at`) VALUES
(2, 21, 1, 1, NULL, '2026-05-20 03:46:08', '2026-05-20 02:00:01', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(4, 7, 1, 1, NULL, NULL, '2026-05-21 11:01:09', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(5, 9, 1, 1, NULL, NULL, '2026-05-21 11:01:09', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(6, 10, 1, 1, NULL, NULL, '2026-05-21 11:01:09', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(7, 11, 1, 1, NULL, NULL, '2026-05-21 11:01:09', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(8, 12, 1, 1, NULL, NULL, '2026-05-21 11:01:09', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(9, 13, 1, 1, NULL, NULL, '2026-05-21 11:01:09', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(13, 30, 1, 1, NULL, NULL, '2026-05-26 12:00:02', NULL, 0, NULL, 'completed', 0, NULL, NULL),
(14, 28, 0, 0, NULL, NULL, NULL, 'No reason provided', 1, 'student_no_show', 'disputed', 0, NULL, NULL),
(16, 1, 1, 1, '2026-06-13 13:29:18', '2026-05-28 18:28:37', NULL, 'student reported: tutor_no_show - Tutor didn\'t even provide meeting link for today session', 1, NULL, 'completed', 0, NULL, NULL),
(18, 35, 0, 0, NULL, NULL, '2026-05-29 13:14:34', NULL, 0, NULL, 'disputed', 1, NULL, NULL),
(24, 22, 0, 1, NULL, '2026-05-31 03:18:59', NULL, NULL, 1, NULL, 'pending', 0, NULL, NULL),
(25, 50, 0, 1, NULL, '2026-05-31 15:49:07', '2026-06-01 17:06:35', NULL, 0, NULL, 'completed', 1, NULL, NULL),
(26, 32, 0, 0, NULL, NULL, '2026-06-01 17:05:50', 'No reason provided', 1, 'student_no_show', 'completed', 1, NULL, NULL),
(27, 37, 0, 1, NULL, '2026-06-09 13:37:54', '2026-06-01 17:06:21', NULL, 1, NULL, 'completed', 1, NULL, NULL),
(29, 20, 0, 1, NULL, '2026-06-03 20:17:19', '2026-06-01 17:06:45', NULL, 1, NULL, 'completed', 1, NULL, NULL),
(30, 24, 0, 1, NULL, '2026-06-03 08:58:20', '2026-06-03 12:00:14', NULL, 0, NULL, 'completed', 1, NULL, NULL),
(31, 34, 0, 0, NULL, NULL, '2026-06-03 12:00:02', NULL, 0, NULL, 'completed', 1, NULL, NULL),
(33, 57, 0, 0, NULL, NULL, '2026-06-05 12:00:02', 'tutor_no_show', 0, NULL, 'disputed', 1, NULL, '2026-06-07 02:43:50'),
(34, 53, 0, 0, NULL, NULL, '2026-06-06 12:00:01', NULL, 0, NULL, 'completed', 1, NULL, NULL),
(35, 45, 0, 0, NULL, NULL, '2026-06-06 12:00:10', NULL, 0, NULL, 'completed', 1, NULL, NULL),
(36, 2, 0, 0, NULL, NULL, '2026-06-06 12:00:19', NULL, 0, NULL, 'completed', 1, NULL, NULL),
(38, 19, 0, 0, NULL, NULL, '2026-06-07 15:37:01', NULL, 0, NULL, 'completed', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `session_reports`
--

CREATE TABLE `session_reports` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `session_time` time NOT NULL,
  `lesson_summary` text NOT NULL,
  `student_progress` varchar(255) DEFAULT NULL,
  `topics_covered` text DEFAULT NULL,
  `homework_given` text DEFAULT NULL,
  `tutor_notes` text DEFAULT NULL,
  `student_feedback` text DEFAULT NULL,
  `materials_used` text DEFAULT NULL,
  `next_session_focus` text DEFAULT NULL,
  `attendance_status` enum('attended','late','absent') DEFAULT 'attended',
  `report_status` enum('draft','submitted','approved','rejected') DEFAULT 'draft',
  `admin_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `submitted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `session_reports`
--

INSERT INTO `session_reports` (`id`, `booking_id`, `tutor_id`, `student_id`, `session_date`, `session_time`, `lesson_summary`, `student_progress`, `topics_covered`, `homework_given`, `tutor_notes`, `student_feedback`, `materials_used`, `next_session_focus`, `attendance_status`, `report_status`, `admin_notes`, `created_at`, `updated_at`, `submitted_at`) VALUES
(1, 30, 3, 2, '2026-05-25', '12:00:00', 'This session focused on improving basic English speaking skills. The student practiced simple daily conversations, answering personal questions, and using common vocabulary in sentences. We also worked on pronunciation and sentence structure during speaking activities.', 'Sharon is becoming more confident when speaking in English. She was able to answer simple questions with less hesitation and showed improvement in pronunciation and vocabulary usage. She is also starting to form longer sentences independently.', 'Self Introdction', 'No homework assigned', 'Student is shy at the beginning but becomes more comfortable after warm-up activities. Needs more practice with grammar accuracy and speaking fluency. Encourage full-sentence answers in future sessions.', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-05-27 00:17:19', '2026-06-04 22:22:57', '2026-05-27 00:49:02'),
(3, 9, 3, 2, '2026-05-13', '12:00:00', 'Learn how to speak in a casual way', 'Still improving', 'Speaking', 'No homework assigned', '', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-05-29 18:33:05', '2026-06-04 22:22:55', '2026-05-30 03:29:56'),
(4, 11, 3, 2, '2026-05-12', '13:00:00', 'Demonstrate how to speak clearly', 'Still unclear', 'English Pronunciation', 'No homework assigned', '', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-05-30 03:31:50', '2026-06-04 22:22:52', '2026-05-30 03:31:50'),
(5, 13, 3, 2, '2026-05-12', '12:00:00', 'Learn how to speak more confidentially', 'It seems that student is a bit afraid to speak English in class as her English is not very good.', 'Pronunciations', 'No homework assigned', '', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-05-30 03:33:25', '2026-06-04 22:22:51', '2026-05-30 03:33:25'),
(6, 12, 3, 2, '2026-05-12', '17:00:00', 'Learn how to speak more confidentially', 'Try to speak loud and clear english, good start', 'pronuncation', 'No homework assigned', '', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-05-30 03:34:37', '2026-06-04 22:22:48', '2026-05-30 03:34:37'),
(7, 10, 3, 2, '2026-05-12', '11:00:00', 'Learn how to speak in front of others', 'Still very unconfident about themselves, but still try their best already.', 'Self confidence', 'No homework assigned', '', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-05-30 03:37:08', '2026-06-04 22:22:29', '2026-05-30 03:37:08'),
(8, 1, 5, 2, '2026-05-28', '12:00:00', 'Learn how to write hiragana', 'Still improving', 'HIRAGANA', 'No homework assigned', '', NULL, 'No materials uploaded', '', 'attended', 'approved', '', '2026-06-03 08:19:00', '2026-06-10 00:36:48', '2026-06-03 08:20:26');

-- --------------------------------------------------------

--
-- Table structure for table `student_favourites`
--

CREATE TABLE `student_favourites` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_favourites`
--

INSERT INTO `student_favourites` (`id`, `student_id`, `tutor_id`, `created_at`) VALUES
(11, 2, 9, '2026-05-08 17:55:46'),
(18, 2, 8, '2026-05-13 11:30:15'),
(27, 4, 3, '2026-05-23 13:09:48');

-- --------------------------------------------------------

--
-- Table structure for table `student_language_proficiency`
--

CREATE TABLE `student_language_proficiency` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `language` varchar(50) NOT NULL,
  `proficiency_level` varchar(20) DEFAULT 'beginner',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_language_proficiency`
--

INSERT INTO `student_language_proficiency` (`id`, `student_id`, `language`, `proficiency_level`, `created_at`, `updated_at`) VALUES
(1, 2, 'Japanese', 'beginner', '2026-05-24 16:56:28', '2026-05-24 16:56:28'),
(2, 2, 'English', 'intermediate', '2026-05-24 16:56:28', '2026-05-24 16:56:28'),
(3, 2, 'Mandarin', 'beginner', '2026-05-24 16:56:28', '2026-05-24 16:56:28'),
(4, 2, 'Korean', 'intermediate', '2026-05-24 16:56:28', '2026-05-24 16:56:28'),
(5, 2, 'Malay', 'advanced', '2026-05-24 16:56:28', '2026-05-24 16:56:28');

-- --------------------------------------------------------

--
-- Table structure for table `student_learning_modes`
--

CREATE TABLE `student_learning_modes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `mode` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_learning_modes`
--

INSERT INTO `student_learning_modes` (`id`, `user_id`, `mode`) VALUES
(3, 4, 'online'),
(4, 11, 'face_to_face'),
(44, 12, 'online'),
(68, 2, 'online'),
(69, 2, 'face_to_face'),
(72, 18, 'online');

-- --------------------------------------------------------

--
-- Table structure for table `student_preferences`
--

CREATE TABLE `student_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language` varchar(100) NOT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','master') DEFAULT 'beginner'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_preferences`
--

INSERT INTO `student_preferences` (`id`, `user_id`, `language`, `proficiency_level`) VALUES
(3, 4, 'English', 'beginner'),
(4, 4, 'Mandarin', 'beginner'),
(5, 11, 'English', 'beginner'),
(68, 12, 'Mandarin', 'beginner'),
(90, 2, 'Mandarin', 'beginner'),
(91, 2, 'English', 'beginner'),
(92, 2, 'Japanese', 'beginner'),
(93, 18, 'English', 'beginner'),
(94, 18, 'Mandarin', 'advanced');

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `grade` decimal(5,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tutor_availability`
--

CREATE TABLE `tutor_availability` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `timezone` varchar(50) DEFAULT 'Asia/Kuala_Lumpur'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_availability`
--

INSERT INTO `tutor_availability` (`id`, `tutor_id`, `day_of_week`, `start_time`, `end_time`, `timezone`) VALUES
(3, 3, 'Thursday', '11:00:00', '19:00:00', 'Asia/Kuala_Lumpur'),
(4, 3, 'Friday', '11:00:00', '19:00:00', 'Asia/Kuala_Lumpur'),
(5, 3, 'Saturday', '11:00:00', '19:00:00', 'Asia/Kuala_Lumpur'),
(6, 5, 'Monday', '09:00:00', '18:00:00', 'Asia/Kuala_Lumpur'),
(7, 5, 'Tuesday', '09:00:00', '18:00:00', 'Asia/Kuala_Lumpur'),
(8, 5, 'Wednesday', '09:00:00', '18:00:00', 'Asia/Kuala_Lumpur'),
(9, 5, 'Thursday', '09:00:00', '18:00:00', 'Asia/Kuala_Lumpur'),
(10, 5, 'Friday', '09:00:00', '18:00:00', 'Asia/Kuala_Lumpur'),
(11, 6, 'Monday', '10:00:00', '20:00:00', 'Asia/Kuala_Lumpur'),
(12, 6, 'Tuesday', '10:00:00', '20:00:00', 'Asia/Kuala_Lumpur'),
(13, 6, 'Wednesday', '10:00:00', '20:00:00', 'Asia/Kuala_Lumpur'),
(14, 6, 'Thursday', '10:00:00', '20:00:00', 'Asia/Kuala_Lumpur'),
(15, 6, 'Friday', '10:00:00', '20:00:00', 'Asia/Kuala_Lumpur'),
(16, 6, 'Saturday', '10:00:00', '20:00:00', 'Asia/Kuala_Lumpur'),
(17, 7, 'Monday', '14:00:00', '21:00:00', 'Asia/Kuala_Lumpur'),
(18, 7, 'Tuesday', '14:00:00', '21:00:00', 'Asia/Kuala_Lumpur'),
(19, 7, 'Wednesday', '14:00:00', '21:00:00', 'Asia/Kuala_Lumpur'),
(20, 7, 'Thursday', '14:00:00', '21:00:00', 'Asia/Kuala_Lumpur'),
(21, 7, 'Friday', '14:00:00', '21:00:00', 'Asia/Kuala_Lumpur'),
(22, 8, 'Saturday', '10:00:00', '17:00:00', 'Asia/Kuala_Lumpur'),
(23, 8, 'Sunday', '10:00:00', '17:00:00', 'Asia/Kuala_Lumpur'),
(24, 9, 'Monday', '08:00:00', '17:00:00', 'Asia/Kuala_Lumpur'),
(25, 9, 'Tuesday', '08:00:00', '17:00:00', 'Asia/Kuala_Lumpur'),
(26, 9, 'Wednesday', '08:00:00', '17:00:00', 'Asia/Kuala_Lumpur'),
(27, 9, 'Thursday', '08:00:00', '17:00:00', 'Asia/Kuala_Lumpur'),
(28, 10, 'Monday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(29, 10, 'Tuesday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(30, 10, 'Wednesday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(31, 10, 'Thursday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(32, 10, 'Friday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(33, 10, 'Saturday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(34, 10, 'Sunday', '00:00:00', '23:59:59', 'Asia/Kuala_Lumpur'),
(39, 3, 'Monday', '11:00:00', '13:30:00', 'Asia/Kuala_Lumpur'),
(40, 3, 'Tuesday', '10:00:00', '16:00:00', 'Asia/Kuala_Lumpur'),
(41, 3, 'Tuesday', '17:00:00', '19:00:00', 'Asia/Kuala_Lumpur');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_bank_details`
--

CREATE TABLE `tutor_bank_details` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `bank_account_number` varchar(50) NOT NULL,
  `bank_account_name` varchar(100) NOT NULL,
  `is_default` tinyint(4) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_bank_details`
--

INSERT INTO `tutor_bank_details` (`id`, `tutor_id`, `bank_name`, `bank_account_number`, `bank_account_name`, `is_default`, `created_at`, `updated_at`) VALUES
(1, 3, 'CIMB Bank', '1122334455', 'FENG XI', 0, '2026-05-30 15:34:24', '2026-06-03 19:31:43'),
(2, 3, 'CIMB Bank', '11442233', 'FENG XI', 1, '2026-05-30 15:35:28', '2026-06-03 19:37:28'),
(7, 3, 'CIMB BANK', '1122334455', 'FENG XI', 0, '2026-06-05 15:04:21', '2026-06-05 15:04:21');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_certificates`
--

CREATE TABLE `tutor_certificates` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `certificate_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_certificates`
--

INSERT INTO `tutor_certificates` (`id`, `tutor_id`, `certificate_name`, `file_path`, `status`, `admin_notes`, `uploaded_at`) VALUES
(2, 13, 'certificate.pdf', 'images.pdf\r\n', 'approved', NULL, '2026-06-02 07:55:16'),
(3, 13, 'cambridge certificate.jpg', 'cert_1780386916_1_a3f024bc.jpg', 'approved', NULL, '2026-06-02 07:55:16'),
(4, 14, 'cambridge certificate.jpg', 'cert_1780532608_0_ef5bbbc6.jpg', 'approved', NULL, '2026-06-04 00:23:28'),
(6, 3, 'English Muet Band 5', 'cert_3_1780573117.jpg', 'rejected', 'Please upload a clearer photo for verification', '2026-06-04 11:38:37');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_languages`
--

CREATE TABLE `tutor_languages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language` varchar(100) NOT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','master') DEFAULT 'intermediate'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_languages`
--

INSERT INTO `tutor_languages` (`id`, `user_id`, `language`, `proficiency_level`) VALUES
(3, 5, 'Japanese', 'intermediate'),
(4, 6, 'English', 'intermediate'),
(5, 7, 'Mandarin', 'intermediate'),
(6, 8, 'Korean', 'intermediate'),
(7, 9, 'Malay', 'intermediate'),
(8, 10, 'English', 'intermediate'),
(23, 3, 'English', 'intermediate'),
(24, 3, 'Mandarin', 'intermediate'),
(25, 3, 'Japanese', 'beginner'),
(26, 13, 'English', 'intermediate'),
(27, 13, 'Japanese', 'intermediate'),
(28, 14, 'Korean', 'intermediate');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_profiles`
--

CREATE TABLE `tutor_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `experience` int(11) DEFAULT 0,
  `rate` varchar(50) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `language_certificate` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_profiles`
--

INSERT INTO `tutor_profiles` (`id`, `user_id`, `experience`, `rate`, `bio`, `language_certificate`) VALUES
(2, 3, 2, '60', 'A passionate language tutor with experience in teaching Mandarin and English. I aim to help students build strong communication skills through structured, engaging, and practical learning methods', NULL),
(3, 5, 3, '45', 'Best for beginner speaking and daily Japanese phrases.', NULL),
(4, 6, 5, '50', 'Good for English speaking confidence and presentations.', NULL),
(5, 7, 4, '48', 'Friendly Mandarin tutor for beginners and tone practice.', NULL),
(6, 8, 2, '46', 'Focus on Korean Hangul and pronunciation.', NULL),
(7, 9, 6, '40', 'Useful for Malay writing and grammar improvement.', NULL),
(8, 10, 4, '47', 'Helpful for English conversation and listening practice.', NULL),
(9, 13, 1, '50', 'Whether you are a beginner or try to improve your language skill, come join my class and start our learning journey.', NULL),
(10, 14, 2, '50', 'TEACH GOOD ENGLISH', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tutor_qualifications`
--

CREATE TABLE `tutor_qualifications` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `qualification_name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_qualifications`
--

INSERT INTO `tutor_qualifications` (`id`, `tutor_id`, `qualification_name`, `created_at`) VALUES
(1, 3, 'HSK Level 4', '2026-06-02 14:09:29'),
(2, 5, 'JLPT N2 Certified', '2026-06-02 14:09:29'),
(3, 6, 'Cambridge CELTA Certified', '2026-06-02 14:09:29'),
(4, 7, 'HSK Level 4', '2026-06-02 14:09:29'),
(5, 8, 'TOPIK Level 3', '2026-06-02 14:09:29'),
(6, 9, 'SPM Bahasa Melayu A+', '2026-06-02 14:09:29'),
(7, 10, 'IELTS 7.5', '2026-06-02 14:09:29'),
(8, 13, 'CERTIFICATE ESOL GRADE C', '2026-06-03 21:57:08'),
(9, 13, 'N2 JAPANESE LANGUAGE PROFICIENCY', '2026-06-03 21:57:08'),
(10, 14, 'ESOL LEVEL C', '2026-06-04 11:23:51');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_teaching_modes`
--

CREATE TABLE `tutor_teaching_modes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `mode` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_teaching_modes`
--

INSERT INTO `tutor_teaching_modes` (`id`, `user_id`, `mode`) VALUES
(3, 5, 'online'),
(4, 5, 'face_to_face'),
(5, 6, 'online'),
(6, 7, 'online'),
(7, 7, 'face_to_face'),
(8, 8, 'online'),
(9, 9, 'face_to_face'),
(10, 10, 'online'),
(25, 3, 'online'),
(26, 3, 'face_to_face'),
(27, 13, 'online'),
(28, 14, 'online');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `role` enum('student','tutor','admin') NOT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'approved',
  `verification_token` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `google_calendar_connected` tinyint(1) DEFAULT 0,
  `google_calendar_asked` tinyint(1) DEFAULT 0,
  `deactivated_at` datetime DEFAULT NULL,
  `deactivation_reason` text DEFAULT NULL,
  `first_promo_applied` tinyint(4) DEFAULT 0,
  `is_first_purchase` tinyint(4) DEFAULT 1,
  `promo_code_used` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `phone`, `role`, `profile_pic`, `created_at`, `status`, `verification_token`, `is_verified`, `google_calendar_connected`, `google_calendar_asked`, `deactivated_at`, `deactivation_reason`, `first_promo_applied`, `is_first_purchase`, `promo_code_used`) VALUES
(1, 'Ali', 'ali@gmail.com', '$2y$10$BFTMIbbp0RhnxUkRdHmG9.BLGGhcNJAXu00jolOoVLWEYF0Et7j9O', '01155532488', 'admin', NULL, '2026-05-06 06:38:20', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(2, 'Sharon', 'morasharon790@gmail.com', '$2y$10$mHZyF3jf7APnAYmK/GtWs.Ng8o5LaPRWwSPMXDO9OKXIoZHsh.Dqm', '01155532488', 'student', 'student_2_1779222498.png', '2026-05-06 09:16:55', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 0, NULL),
(3, 'Feng X', 'fengxiii87@gmail.com', '$2y$10$LiwtHaYor.N79M.LSGZzfOOXwSy3YWmlBaV0stZUUpmRtYj1l4qPy', '014 2739441', 'tutor', 'tutor_3_1779895960.jpg', '2026-05-06 13:03:13', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(4, 'Sarah', 'sarah@gmail.com', '$2y$10$pto8/27JofkiIgpqJRgDeeRPGtV0TQIjv/mzPTl3rAHfMAly69Wau', '0123456701', 'student', NULL, '2026-05-07 05:59:23', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(5, 'Haruka Tan', 'haruka@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456781', 'tutor', 'haruka.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(6, 'Daniel Lee', 'daniel@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456782', 'tutor', 'daniel.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(7, 'Alicia Wong', 'alicia@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456783', 'tutor', 'alicia.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(8, 'Kim Jisoo', 'kimjisoo@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456784', 'tutor', 'kimjisoo.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(9, 'Farah Nabila', 'farah@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456785', 'tutor', 'farah.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 0, 0, NULL, '', 0, 1, NULL),
(10, 'Aina Yusuf', 'aina@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456786', 'tutor', 'aina.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(11, 'Kay', 'kay@gmail.com', '$2y$10$Ky3faHhz7Zh4YviWQwDrw.KzGT.X3F9HhIVftf8mN/WNa5K7oEeyi', NULL, 'student', NULL, '2026-05-11 14:36:20', 'approved', '9d0a2437b772fe9c8e4f1532a1dfb28df627cb22902ee5a0d0998dab112677ac', 0, 0, 0, NULL, NULL, 0, 1, NULL),
(12, 'Sia', 'chinming0210@gmail.com', '$2y$10$.f4052SpkFwpGCDOGYr8yOfcK3u7RycxnobH/NCYYuqHvAtpSv4Ta', NULL, 'student', NULL, '2026-05-18 03:30:12', 'approved', '7aaea97bc148c358f33502ef5eb882da23e96482a2c47d104fa6255c7668ff5f', 0, 0, 0, NULL, NULL, 0, 1, NULL),
(13, 'Kay Hueen', 'kayhueen5@gmail.com', '$2y$10$NF7ux3xADRav1RrK3YU55eI5hVwInHgD9B6v6jih3AME4D8.nJh5e', '0142739441', 'tutor', 'profile_1780386916_16b66aea.jpg', '2026-06-02 07:55:16', 'approved', '8ed7c5f08dd4a9fca9ebe01124b976dd3a98bcd5dc4808a74c125d429a4f0028', 0, 0, 0, NULL, NULL, 0, 1, NULL),
(14, 'Gg', 'gg@gmail.com', '$2y$10$VRtHuo26qNSdf2Yctf/2EOF9xeLXFtOlSFXmrfeQbQ/pkVMhYFci6', '1165048088', 'tutor', 'profile_1780532607_739d2454.jpg', '2026-06-04 00:23:27', 'approved', 'f53809178c3086abc4be064b3a15f445241a0649f0dfebfd1be6c7f6fb84be4c', 0, 0, 0, NULL, NULL, 0, 1, NULL),
(16, 'WAN DI', 'wandi@gmail.com', '$2y$10$4BFtp8i1kH0NIgaTCxCicuHaiiYOBaFEZNHqxLKYnpVxEFRBUy4A2', '0123776887', 'tutor', NULL, '2026-06-05 07:47:25', 'inactive', NULL, 0, 0, 0, '2026-06-05 17:38:34', NULL, 0, 1, NULL),
(18, 'sa', 'sa@gmail.com', '$2y$10$XzX4uZhUGBOFCqcFeE9m2u3w612mmAB9k69MG/OrrqHU9xbjV.o4i', '123116887', 'student', NULL, '2026-06-08 15:20:29', 'approved', '157d54d2bf53903dfb093ec1504d03345e7e5f666db3f068439df4bffd9cfd4a', 0, 0, 0, NULL, NULL, 0, 1, NULL),
(19, 'Nur Aisyah Binti Abdullah', 'aisyah.abdullah@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456701', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(20, 'Lee Wei Chen', 'weichen.lee@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456702', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(21, 'Siti Nurhaliza Binti Mohd', 'siti.nurhaliza@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456703', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(22, 'Rajesh Kumar A/L Subramaniam', 'rajesh.kumar@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456704', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(23, 'Olivia Chen', 'olivia.chen@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456705', 'student', 'student_23_1781262520.png', '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(24, 'Muhammad Faiz Bin Rosli', 'faiz.rosli@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456706', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(25, 'Tan Hui Ling', 'huiling.tan@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456707', 'student', 'student_25_1781272604.png', '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(26, 'Kavitha A/P Rajendran', 'kavitha.rajendran@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456708', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(27, 'James Wong', 'james.wong@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456709', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL),
(28, 'Nadiah Binti Kamaruddin', 'nadiah.kamaruddin@example.com', '$2y$10$U3PE5jdOuvB4KhdQGI0p4.1iRm/LDjvH9NHkSXPebdIIiRwG6Xv7C', '0123456710', 'student', NULL, '2026-06-10 15:08:29', 'approved', NULL, 0, 0, 0, NULL, NULL, 0, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_locations`
--

CREATE TABLE `user_locations` (
  `user_id` int(11) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `location_type` enum('teaching','learning') DEFAULT 'teaching'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_locations`
--

INSERT INTO `user_locations` (`user_id`, `location`, `location_type`) VALUES
(2, 'Kuala Lumpur', 'teaching'),
(3, 'Johor Bahru', 'teaching'),
(4, 'Johor Bahru', 'teaching'),
(5, 'Penang', 'teaching'),
(7, 'Johor Bahru', 'teaching'),
(9, 'Kota Kinabalu', 'teaching'),
(11, 'Johor Bahru', 'teaching');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`),
  ADD KEY `idx_booking` (`booking_id`),
  ADD KEY `idx_student_id` (`student_id`);

--
-- Indexes for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `tutor_id` (`tutor_id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `attendance_proofs`
--
ALTER TABLE `attendance_proofs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `tutor_id` (`tutor_id`);

--
-- Indexes for table `disputes`
--
ALTER TABLE `disputes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `tutor_id` (`tutor_id`),
  ADD KEY `idx_payment_id` (`payment_id`),
  ADD KEY `idx_dispute_type` (`dispute_type`);

--
-- Indexes for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `idx_student_id` (`student_id`);

--
-- Indexes for table `meeting_logs`
--
ALTER TABLE `meeting_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

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
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `payout_requests`
--
ALTER TABLE `payout_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `ratings`
--
ALTER TABLE `ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `tutor_id` (`tutor_id`);

--
-- Indexes for table `reschedule_requests`
--
ALTER TABLE `reschedule_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `tutor_id` (`tutor_id`);

--
-- Indexes for table `session_completion`
--
ALTER TABLE `session_completion`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_booking_id` (`booking_id`);

--
-- Indexes for table `session_reports`
--
ALTER TABLE `session_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_booking_id` (`booking_id`),
  ADD KEY `idx_tutor_id` (`tutor_id`),
  ADD KEY `idx_session_date` (`session_date`);

--
-- Indexes for table `student_favourites`
--
ALTER TABLE `student_favourites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fav` (`student_id`,`tutor_id`);

--
-- Indexes for table `student_language_proficiency`
--
ALTER TABLE `student_language_proficiency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_language` (`student_id`,`language`);

--
-- Indexes for table `student_learning_modes`
--
ALTER TABLE `student_learning_modes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `student_preferences`
--
ALTER TABLE `student_preferences`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_assignment` (`assignment_id`);

--
-- Indexes for table `tutor_availability`
--
ALTER TABLE `tutor_availability`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tutor_id` (`tutor_id`);

--
-- Indexes for table `tutor_bank_details`
--
ALTER TABLE `tutor_bank_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`);

--
-- Indexes for table `tutor_certificates`
--
ALTER TABLE `tutor_certificates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`);

--
-- Indexes for table `tutor_languages`
--
ALTER TABLE `tutor_languages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tutor_profiles`
--
ALTER TABLE `tutor_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tutor_qualifications`
--
ALTER TABLE `tutor_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`);

--
-- Indexes for table `tutor_teaching_modes`
--
ALTER TABLE `tutor_teaching_modes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_locations`
--
ALTER TABLE `user_locations`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `attendance_proofs`
--
ALTER TABLE `attendance_proofs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `learning_materials`
--
ALTER TABLE `learning_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `meeting_logs`
--
ALTER TABLE `meeting_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=330;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=89;

--
-- AUTO_INCREMENT for table `payout_requests`
--
ALTER TABLE `payout_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `reschedule_requests`
--
ALTER TABLE `reschedule_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `session_completion`
--
ALTER TABLE `session_completion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `session_reports`
--
ALTER TABLE `session_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `student_favourites`
--
ALTER TABLE `student_favourites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `student_language_proficiency`
--
ALTER TABLE `student_language_proficiency`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `student_learning_modes`
--
ALTER TABLE `student_learning_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `student_preferences`
--
ALTER TABLE `student_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tutor_availability`
--
ALTER TABLE `tutor_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `tutor_bank_details`
--
ALTER TABLE `tutor_bank_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tutor_certificates`
--
ALTER TABLE `tutor_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tutor_languages`
--
ALTER TABLE `tutor_languages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `tutor_profiles`
--
ALTER TABLE `tutor_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `tutor_qualifications`
--
ALTER TABLE `tutor_qualifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tutor_teaching_modes`
--
ALTER TABLE `tutor_teaching_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD CONSTRAINT `assignment_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignment_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `assignment_submissions_ibfk_3` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `assignment_submissions_ibfk_4` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`);

--
-- Constraints for table `attendance_proofs`
--
ALTER TABLE `attendance_proofs`
  ADD CONSTRAINT `attendance_proofs_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`);

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `disputes_ibfk_3` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `disputes_ibfk_4` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `disputes_ibfk_5` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `disputes_ibfk_6` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD CONSTRAINT `learning_materials_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `learning_materials_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meeting_logs`
--
ALTER TABLE `meeting_logs`
  ADD CONSTRAINT `meeting_logs_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payout_requests`
--
ALTER TABLE `payout_requests`
  ADD CONSTRAINT `payout_requests_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `payout_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ratings`
--
ALTER TABLE `ratings`
  ADD CONSTRAINT `ratings_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  ADD CONSTRAINT `ratings_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `ratings_ibfk_3` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `reschedule_requests`
--
ALTER TABLE `reschedule_requests`
  ADD CONSTRAINT `reschedule_requests_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reschedule_requests_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reschedule_requests_ibfk_3` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `session_completion`
--
ALTER TABLE `session_completion`
  ADD CONSTRAINT `session_completion_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`);

--
-- Constraints for table `session_reports`
--
ALTER TABLE `session_reports`
  ADD CONSTRAINT `session_reports_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `session_reports_ibfk_2` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `session_reports_ibfk_3` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `student_language_proficiency`
--
ALTER TABLE `student_language_proficiency`
  ADD CONSTRAINT `student_language_proficiency_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_learning_modes`
--
ALTER TABLE `student_learning_modes`
  ADD CONSTRAINT `student_learning_modes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_preferences`
--
ALTER TABLE `student_preferences`
  ADD CONSTRAINT `student_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `tutor_availability`
--
ALTER TABLE `tutor_availability`
  ADD CONSTRAINT `tutor_availability_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tutor_bank_details`
--
ALTER TABLE `tutor_bank_details`
  ADD CONSTRAINT `tutor_bank_details_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tutor_certificates`
--
ALTER TABLE `tutor_certificates`
  ADD CONSTRAINT `tutor_certificates_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tutor_languages`
--
ALTER TABLE `tutor_languages`
  ADD CONSTRAINT `tutor_languages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tutor_profiles`
--
ALTER TABLE `tutor_profiles`
  ADD CONSTRAINT `tutor_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tutor_qualifications`
--
ALTER TABLE `tutor_qualifications`
  ADD CONSTRAINT `tutor_qualifications_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tutor_teaching_modes`
--
ALTER TABLE `tutor_teaching_modes`
  ADD CONSTRAINT `tutor_teaching_modes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_locations`
--
ALTER TABLE `user_locations`
  ADD CONSTRAINT `user_locations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
