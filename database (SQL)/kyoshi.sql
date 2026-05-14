-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 14, 2026 at 06:34 AM
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
-- Database: `kyoshi`
--

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
  `status` enum('pending','accepted','confirmed','completed','cancelled','rescheduled') DEFAULT NULL,
  `cancelled_by` enum('student','tutor','admin') DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `meeting_location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `focus` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `student_id`, `tutor_id`, `language`, `learning_mode`, `booking_date`, `booking_time`, `status`, `cancelled_by`, `cancel_reason`, `meeting_location`, `created_at`, `notes`, `focus`) VALUES
(1, 2, 5, 'Japanese', 'online', '2026-05-28', '12:00:00', 'confirmed', NULL, NULL, NULL, '2026-05-09 07:25:23', '', 'Writing'),
(2, 2, 10, 'English', 'online', '2026-05-21', '01:00:00', 'pending', 'student', 'Cancelled by student', NULL, '2026-05-09 07:25:23', '', 'Speaking'),
(3, 2, 3, 'Mandarin', 'face_to_face', '2026-04-10', '09:00:00', 'completed', NULL, NULL, 'KLCC', '2026-05-09 07:25:23', NULL, NULL),
(4, 2, 6, 'English', 'online', '2026-04-01', '11:00:00', 'completed', NULL, NULL, NULL, '2026-05-09 07:25:23', NULL, NULL),
(5, 2, 8, 'Korean', 'online', '2026-03-15', '15:00:00', 'cancelled', NULL, NULL, NULL, '2026-05-09 07:25:23', NULL, NULL),
(6, 4, 5, 'Japanese', 'online', '2026-05-18', '10:00:00', 'confirmed', NULL, NULL, NULL, '2026-05-09 07:25:23', NULL, NULL),
(7, 4, 9, 'Malay', 'face_to_face', '2026-04-20', '13:00:00', 'completed', NULL, NULL, 'Mid Valley', '2026-05-09 07:25:23', NULL, NULL),
(8, 2, 3, 'English', 'online', '2026-05-12', '12:00:00', 'cancelled', 'admin', NULL, NULL, '2026-05-09 13:09:59', '', 'Speaking'),
(9, 2, 3, 'English', 'online', '2026-05-13', '12:00:00', 'cancelled', NULL, NULL, NULL, '2026-05-09 13:09:59', '', 'Speaking'),
(10, 2, 3, 'English', 'online', '2026-05-12', '11:00:00', 'completed', NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking'),
(11, 2, 3, 'English', 'online', '2026-05-12', '13:00:00', 'completed', NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking'),
(12, 2, 3, 'English', 'online', '2026-05-12', '17:00:00', 'completed', NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking'),
(13, 2, 3, 'English', 'online', '2026-05-12', '12:00:00', 'completed', NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking'),
(14, 2, 9, 'Malay', 'face_to_face', '2026-05-27', '10:00:00', 'cancelled', 'student', 'Cancelled by student', 'Starbucks, Jalan Borneo, Menggatal, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88813, Malaysia', '2026-05-11 04:49:50', '', 'Speaking'),
(15, 2, 3, 'Mandarin', 'online', '2026-05-12', '16:00:00', 'cancelled', 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking'),
(16, 2, 3, 'Mandarin', 'online', '2026-05-12', '18:00:00', 'cancelled', 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking'),
(17, 2, 3, 'Mandarin', 'online', '2026-05-28', '12:00:00', 'cancelled', 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking'),
(18, 2, 3, 'Mandarin', 'online', '2026-05-28', '17:00:00', 'cancelled', 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking'),
(19, 2, 8, 'Korean', 'online', '2026-06-06', '12:00:00', 'pending', NULL, NULL, NULL, '2026-05-13 16:10:03', '', 'Speaking, Listening'),
(20, 2, 8, 'Korean', 'online', '2026-06-13', '12:00:00', 'pending', NULL, NULL, NULL, '2026-05-13 16:10:03', '', 'Speaking, Listening'),
(21, 2, 9, 'Malay', 'face_to_face', '2026-05-14', '09:00:00', 'pending', NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking'),
(22, 2, 9, 'Malay', 'face_to_face', '2026-05-26', '09:00:00', 'pending', NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking'),
(23, 2, 9, 'Malay', 'face_to_face', '2026-05-20', '09:00:00', 'pending', NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'general',
  `is_read` tinyint(1) DEFAULT 0,
  `related_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `related_id`, `created_at`) VALUES
(1, 10, 'Booking Cancelled', 'Sharon has cancelled their English lesson booking on 21 May 2026 at 1:00 AM.', 'booking_cancelled', 0, 2, '2026-05-10 15:31:11'),
(2, 3, 'Booking Cancelled', 'Sharon has cancelled their Mandarin lesson booking on 28 May 2026 at 5:00 PM.', 'booking_cancelled', 0, 18, '2026-05-13 10:30:47'),
(3, 3, 'Booking Cancelled', 'Sharon has cancelled their Mandarin lesson booking on 28 May 2026 at 12:00 PM.', 'booking_cancelled', 0, 17, '2026-05-13 10:30:47'),
(4, 3, 'Booking Cancelled', 'Sharon has cancelled their Mandarin lesson booking on 12 May 2026 at 6:00 PM.', 'booking_cancelled', 0, 16, '2026-05-13 10:30:47'),
(5, 3, 'Booking Cancelled', 'Sharon has cancelled their Mandarin lesson booking on 12 May 2026 at 4:00 PM.', 'booking_cancelled', 0, 15, '2026-05-13 10:30:47'),
(6, 9, 'Booking Cancelled', 'Sharon has cancelled their Malay lesson booking on 27 May 2026 at 10:00 AM.', 'booking_cancelled', 0, 14, '2026-05-13 10:30:47');

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
(7, 'kay@gmail.com', '5e2c3e432f639a70e7e9c1ae77ceec27231ab35b7854c94e8d4b5e1a3da6ee9c', '2026-05-14 03:59:48');

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
  `payment_method` varchar(50) DEFAULT NULL,
  `status` enum('pending','verified','failed') DEFAULT 'pending',
  `receipt_number` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `booking_id`, `student_id`, `tutor_id`, `amount`, `payment_method`, `status`, `receipt_number`, `notes`, `proof_image`, `created_at`) VALUES
(1, 2, 2, 10, 47.00, 'online_banking', 'failed', NULL, NULL, NULL, '2026-05-09 18:59:26'),
(2, 1, NULL, NULL, 45.00, 'stripe', '', NULL, NULL, NULL, '2026-05-10 12:21:22');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ratings`
--

INSERT INTO `ratings` (`id`, `booking_id`, `student_id`, `tutor_id`, `rating`, `comment`, `created_at`) VALUES
(1, 3, 2, 3, 4, 'Good Mandarin class, very patient teacher!', '2026-05-09 07:25:23'),
(2, 4, 2, 6, 5, 'Daniel explains English grammar really well. Highly recommend!', '2026-05-09 07:25:23'),
(3, 7, 4, 9, 5, 'Farah is amazing, very friendly and clear explanation.', '2026-05-09 07:25:23');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reschedule_requests`
--

INSERT INTO `reschedule_requests` (`id`, `booking_id`, `student_id`, `tutor_id`, `old_date`, `old_time`, `new_date`, `new_time`, `language`, `learning_mode`, `focus`, `notes`, `meeting_location`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 5, '2026-05-28', '12:00:00', '2026-05-13', '16:00:00', 'Japanese', 'online', 'Writing', '', NULL, 'pending', '2026-05-10 15:00:49', '2026-05-10 15:00:49');

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
(12, 2, 5, '2026-05-08 17:56:08'),
(16, 2, 3, '2026-05-09 13:57:41'),
(18, 2, 8, '2026-05-13 11:30:15'),
(19, 2, 10, '2026-05-14 04:13:07'),
(20, 2, 7, '2026-05-14 04:15:41');

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
(41, 2, 'online'),
(42, 2, 'face_to_face'),
(43, 2, 'online');

-- --------------------------------------------------------

--
-- Table structure for table `student_preferences`
--

CREATE TABLE `student_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_preferences`
--

INSERT INTO `student_preferences` (`id`, `user_id`, `language`) VALUES
(3, 4, 'English'),
(4, 4, 'Mandarin'),
(5, 11, 'English'),
(65, 2, 'Mandarin'),
(66, 2, 'English'),
(67, 2, 'Japanese');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_availability`
--

CREATE TABLE `tutor_availability` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_availability`
--

INSERT INTO `tutor_availability` (`id`, `tutor_id`, `day_of_week`, `start_time`, `end_time`) VALUES
(1, 3, 'Tuesday', '11:00:00', '19:00:00'),
(2, 3, 'Wednesday', '11:00:00', '19:00:00'),
(3, 3, 'Thursday', '11:00:00', '19:00:00'),
(4, 3, 'Friday', '11:00:00', '19:00:00'),
(5, 3, 'Saturday', '11:00:00', '19:00:00'),
(6, 5, 'Monday', '09:00:00', '18:00:00'),
(7, 5, 'Tuesday', '09:00:00', '18:00:00'),
(8, 5, 'Wednesday', '09:00:00', '18:00:00'),
(9, 5, 'Thursday', '09:00:00', '18:00:00'),
(10, 5, 'Friday', '09:00:00', '18:00:00'),
(11, 6, 'Monday', '10:00:00', '20:00:00'),
(12, 6, 'Tuesday', '10:00:00', '20:00:00'),
(13, 6, 'Wednesday', '10:00:00', '20:00:00'),
(14, 6, 'Thursday', '10:00:00', '20:00:00'),
(15, 6, 'Friday', '10:00:00', '20:00:00'),
(16, 6, 'Saturday', '10:00:00', '20:00:00'),
(17, 7, 'Monday', '14:00:00', '21:00:00'),
(18, 7, 'Tuesday', '14:00:00', '21:00:00'),
(19, 7, 'Wednesday', '14:00:00', '21:00:00'),
(20, 7, 'Thursday', '14:00:00', '21:00:00'),
(21, 7, 'Friday', '14:00:00', '21:00:00'),
(22, 8, 'Saturday', '10:00:00', '17:00:00'),
(23, 8, 'Sunday', '10:00:00', '17:00:00'),
(24, 9, 'Monday', '08:00:00', '17:00:00'),
(25, 9, 'Tuesday', '08:00:00', '17:00:00'),
(26, 9, 'Wednesday', '08:00:00', '17:00:00'),
(27, 9, 'Thursday', '08:00:00', '17:00:00'),
(28, 10, 'Monday', '00:00:00', '23:59:59'),
(29, 10, 'Tuesday', '00:00:00', '23:59:59'),
(30, 10, 'Wednesday', '00:00:00', '23:59:59'),
(31, 10, 'Thursday', '00:00:00', '23:59:59'),
(32, 10, 'Friday', '00:00:00', '23:59:59'),
(33, 10, 'Saturday', '00:00:00', '23:59:59'),
(34, 10, 'Sunday', '00:00:00', '23:59:59');

-- --------------------------------------------------------

--
-- Table structure for table `tutor_languages`
--

CREATE TABLE `tutor_languages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_languages`
--

INSERT INTO `tutor_languages` (`id`, `user_id`, `language`) VALUES
(1, 3, 'English'),
(2, 3, 'Mandarin'),
(3, 5, 'Japanese'),
(4, 6, 'English'),
(5, 7, 'Mandarin'),
(6, 8, 'Korean'),
(7, 9, 'Malay'),
(8, 10, 'English');

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
  `language_certificate` varchar(255) DEFAULT NULL,
  `qualification` varchar(255) DEFAULT NULL,
  `availability` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tutor_profiles`
--

INSERT INTO `tutor_profiles` (`id`, `user_id`, `experience`, `rate`, `bio`, `language_certificate`, `qualification`, `availability`) VALUES
(2, 3, 2, '50', 'Experienced Mandarin tutor specializing in conversational skills and tone practice.', NULL, 'HSK Level 5', 'Tue-Sat, 11AM-7PM'),
(3, 5, 3, '45', 'Best for beginner speaking and daily Japanese phrases.', NULL, 'JLPT N2 Certified', 'Mon-Fri, 9AM-6PM'),
(4, 6, 5, '50', 'Good for English speaking confidence and presentations.', NULL, 'Cambridge CELTA Certified', 'Mon-Sat, 10AM-8PM'),
(5, 7, 4, '48', 'Friendly Mandarin tutor for beginners and tone practice.', NULL, 'HSK Level 4', 'Weekdays, 2PM-9PM'),
(6, 8, 2, '46', 'Focus on Korean Hangul and pronunciation.', NULL, 'TOPIK Level 3', 'Weekends, 10AM-5PM'),
(7, 9, 6, '40', 'Useful for Malay writing and grammar improvement.', NULL, 'SPM Bahasa Melayu A+', 'Mon-Thu, 8AM-5PM'),
(8, 10, 4, '47', 'Helpful for English conversation and listening practice.', NULL, 'IELTS 7.5', 'Flexible, by appointment');

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
(1, 3, 'online'),
(2, 3, 'face_to_face'),
(3, 5, 'online'),
(4, 5, 'face_to_face'),
(5, 6, 'online'),
(6, 7, 'online'),
(7, 7, 'face_to_face'),
(8, 8, 'online'),
(9, 9, 'face_to_face'),
(10, 10, 'online');

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
  `is_verified` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `phone`, `role`, `profile_pic`, `created_at`, `status`, `verification_token`, `is_verified`) VALUES
(1, 'Ali', 'ali@gmail.com', '$2y$10$BFTMIbbp0RhnxUkRdHmG9.BLGGhcNJAXu00jolOoVLWEYF0Et7j9O', NULL, 'admin', NULL, '2026-05-06 06:38:20', 'approved', NULL, 0),
(2, 'Sharon', 'morasharon790@gmail.com', '$2y$10$Do.N5c5VZNnvsTuaS8vhR.JIKUmh66I0CGh0ArPX/jdzakKSg3.Ry', '', 'student', 'sharon.jpg', '2026-05-06 09:16:55', 'approved', NULL, 0),
(3, 'Feng Xi', 'fengxiii87@gmail.com', '$2y$10$LiwtHaYor.N79M.LSGZzfOOXwSy3YWmlBaV0stZUUpmRtYj1l4qPy', '0123456789', 'tutor', 'fengxi.jpg', '2026-05-06 13:03:13', 'approved', NULL, 0),
(4, 'Sarah', 'sarah@gmail.com', '$2y$10$pto8/27JofkiIgpqJRgDeeRPGtV0TQIjv/mzPTl3rAHfMAly69Wau', '', 'student', '', '2026-05-07 05:59:23', 'approved', NULL, 0),
(5, 'Haruka Tan', 'haruka@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456781', 'tutor', 'haruka.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0),
(6, 'Daniel Lee', 'daniel@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456782', 'tutor', 'daniel.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0),
(7, 'Alicia Wong', 'alicia@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456783', 'tutor', 'alicia.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0),
(8, 'Kim Jisoo', 'kimjisoo@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456784', 'tutor', 'kimjisoo.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0),
(9, 'Farah Nabila', 'farah@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456785', 'tutor', 'farah.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0),
(10, 'Aina Yusuf', 'aina@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456786', 'tutor', 'aina.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0),
(11, 'Kay', 'kay@gmail.com', '$2y$10$Ky3faHhz7Zh4YviWQwDrw.KzGT.X3F9HhIVftf8mN/WNa5K7oEeyi', '', 'student', '', '2026-05-11 14:36:20', 'approved', '9d0a2437b772fe9c8e4f1532a1dfb28df627cb22902ee5a0d0998dab112677ac', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_locations`
--

CREATE TABLE `user_locations` (
  `user_id` int(11) NOT NULL,
  `location` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_locations`
--

INSERT INTO `user_locations` (`user_id`, `location`) VALUES
(2, 'Kuala Lumpur'),
(3, 'Kuala Lumpur'),
(4, 'Johor Bahru'),
(5, 'Penang'),
(7, 'Johor Bahru'),
(9, 'Kota Kinabalu'),
(11, 'Johor Bahru');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `tutor_id` (`tutor_id`);

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
-- Indexes for table `student_favourites`
--
ALTER TABLE `student_favourites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fav` (`student_id`,`tutor_id`);

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
-- Indexes for table `tutor_availability`
--
ALTER TABLE `tutor_availability`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_tutor_day` (`tutor_id`,`day_of_week`);

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
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `reschedule_requests`
--
ALTER TABLE `reschedule_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_favourites`
--
ALTER TABLE `student_favourites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `student_learning_modes`
--
ALTER TABLE `student_learning_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `student_preferences`
--
ALTER TABLE `student_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `tutor_availability`
--
ALTER TABLE `tutor_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `tutor_languages`
--
ALTER TABLE `tutor_languages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tutor_profiles`
--
ALTER TABLE `tutor_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tutor_teaching_modes`
--
ALTER TABLE `tutor_teaching_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`);

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
-- Constraints for table `tutor_availability`
--
ALTER TABLE `tutor_availability`
  ADD CONSTRAINT `tutor_availability_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
