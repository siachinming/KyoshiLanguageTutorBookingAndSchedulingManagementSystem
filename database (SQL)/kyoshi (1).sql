-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 24, 2026 at 12:09 PM
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
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `total_points` int(11) DEFAULT 100,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `grade` varchar(10) DEFAULT NULL
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
  `status` enum('pending','accepted','confirmed','completed','cancelled','rescheduled') DEFAULT NULL,
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
  `proficiency_level` varchar(20) DEFAULT 'beginner'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `student_id`, `tutor_id`, `language`, `learning_mode`, `booking_date`, `booking_time`, `total_amount`, `status`, `completed_at`, `auto_completed`, `cancelled_by`, `cancel_reason`, `meeting_location`, `created_at`, `notes`, `focus`, `meeting_link`, `link_provided_at`, `link_reminder_sent`, `proficiency_level`) VALUES
(1, 2, 5, 'Japanese', 'online', '2026-05-28', '12:00:00', 45.00, 'rescheduled', NULL, 0, NULL, NULL, NULL, '2026-05-09 07:25:23', '', 'Writing', NULL, NULL, 0, 'beginner'),
(2, 2, 10, 'English', 'online', '2026-05-21', '01:00:00', 47.00, 'cancelled', NULL, 0, 'student', 'Payment not received before session time', NULL, '2026-05-09 07:25:23', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(3, 2, 3, 'Mandarin', 'face_to_face', '2026-04-10', '09:00:00', 50.00, 'cancelled', NULL, 0, 'student', NULL, 'KLCC', '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner'),
(4, 2, 6, 'English', 'online', '2026-04-01', '11:00:00', 50.00, 'cancelled', NULL, 0, 'tutor', 'Not free at that moment', NULL, '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner'),
(5, 2, 8, 'Korean', 'online', '2026-03-15', '15:00:00', 46.00, 'cancelled', NULL, 0, 'student', NULL, NULL, '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner'),
(6, 4, 5, 'Japanese', 'online', '2026-05-18', '10:00:00', 45.00, 'completed', '2026-05-20 00:19:29', 1, NULL, NULL, NULL, '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner'),
(7, 4, 9, 'Malay', 'face_to_face', '2026-04-20', '13:00:00', 40.00, 'completed', NULL, 0, NULL, NULL, 'Mid Valley', '2026-05-09 07:25:23', NULL, NULL, NULL, NULL, 0, 'beginner'),
(8, 2, 3, 'English', 'online', '2026-05-12', '12:00:00', 50.00, 'cancelled', NULL, 0, 'admin', NULL, NULL, '2026-05-09 13:09:59', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(9, 2, 3, 'English', 'online', '2026-05-13', '12:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-09 13:09:59', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(10, 2, 3, 'English', 'online', '2026-05-12', '11:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(11, 2, 3, 'English', 'online', '2026-05-12', '13:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(12, 2, 3, 'English', 'online', '2026-05-12', '17:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(13, 2, 3, 'English', 'online', '2026-05-12', '12:00:00', 50.00, 'completed', NULL, 0, NULL, NULL, NULL, '2026-05-11 04:46:30', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(14, 2, 9, 'Malay', 'face_to_face', '2026-05-27', '10:00:00', 40.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', 'Starbucks, Jalan Borneo, Menggatal, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88813, Malaysia', '2026-05-11 04:49:50', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(15, 2, 3, 'Mandarin', 'online', '2026-05-12', '16:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(16, 2, 3, 'Mandarin', 'online', '2026-05-12', '18:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(17, 2, 3, 'Mandarin', 'online', '2026-05-27', '14:00:00', 50.00, 'accepted', NULL, 0, '', '', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(18, 2, 3, 'Mandarin', 'online', '2026-05-28', '17:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-12 13:35:55', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(19, 2, 8, 'Korean', 'online', '2026-06-06', '12:00:00', 46.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-05-13 16:10:03', '', 'Speaking, Listening', NULL, NULL, 0, 'beginner'),
(20, 2, 8, 'Korean', 'online', '2026-06-13', '12:00:00', 46.00, 'rescheduled', NULL, 0, NULL, NULL, NULL, '2026-05-13 16:10:03', '', 'Speaking, Listening', NULL, NULL, 0, 'beginner'),
(21, 2, 9, 'Malay', 'face_to_face', '2026-05-14', '09:00:00', 40.00, 'completed', '2026-05-20 03:46:08', 1, NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(22, 2, 9, 'Malay', 'face_to_face', '2026-05-26', '09:00:00', 40.00, 'rescheduled', NULL, 0, NULL, NULL, 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(23, 2, 9, 'Malay', 'face_to_face', '2026-05-20', '09:00:00', 40.00, 'cancelled', NULL, 0, NULL, 'Payment not received before session time', 'Starbucks, Jalan Kepayan, Kepayan, Kota Kinabalu, Bahagian Pantai Barat, Sabah, 88740, Malaysia', '2026-05-14 04:07:51', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(24, 2, 10, 'English', 'online', '2026-05-29', '12:00:00', 47.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-05-17 17:20:29', '', 'Listening, Reading', NULL, NULL, 0, 'beginner'),
(25, 2, 7, 'Mandarin', 'online', '2026-05-20', '18:00:00', 48.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-17 20:24:28', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(26, 2, 5, 'Japanese', 'online', '2026-05-20', '11:00:00', 45.00, 'cancelled', NULL, 0, 'student', 'Cancelled by student', NULL, '2026-05-18 04:02:38', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(27, 2, 5, 'Japanese', 'online', '2026-05-29', '16:00:00', 45.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-05-19 12:02:46', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(28, 2, 3, 'Mandarin', 'face_to_face', '2026-05-21', '12:00:00', 50.00, 'confirmed', NULL, 0, NULL, NULL, 'Starbucks, Jalan 13/6, Seksyen 13, Petaling Jaya, Petaling, Selangor, 46400, Malaysia', '2026-05-20 19:39:42', '', 'Listening', NULL, NULL, 0, 'beginner'),
(29, 2, 10, 'English', 'online', '2026-05-29', '17:00:00', 47.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-05-21 03:29:09', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(30, 2, 3, 'English', 'face_to_face', '2026-05-25', '12:00:00', 50.00, 'confirmed', NULL, 0, NULL, NULL, 'Kuala Lumpur, Malaysia', '2026-05-21 03:29:44', '', 'Speaking', '', '2026-05-24 13:34:27', 0, 'beginner'),
(31, 2, 3, 'Mandarin', 'face_to_face', '2026-05-26', '18:00:00', 50.00, 'accepted', NULL, 0, NULL, NULL, 'Setapak, Kampung Padang Balang, Kuala Lumpur, 53000, Malaysia', '2026-05-22 12:17:41', '', 'Speaking, Reading', NULL, NULL, 0, 'beginner'),
(32, 2, 3, 'Mandarin', 'online', '2026-05-30', '15:00:00', 50.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-05-22 12:34:08', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(33, 2, 3, 'Mandarin', 'online', '2026-05-30', '18:00:00', 50.00, 'accepted', NULL, 0, NULL, NULL, NULL, '2026-05-22 12:34:08', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(34, 2, 3, 'English', 'face_to_face', '2026-05-29', '18:00:00', 50.00, 'pending', NULL, 0, NULL, NULL, 'ZUS Coffee, Avenue 5, Bangsar South, Pantai Dalam, Kuala Lumpur, 59200, Malaysia', '2026-05-22 14:42:14', '', 'Reading', NULL, NULL, 0, 'beginner'),
(35, 2, 3, 'English', 'face_to_face', '2026-05-29', '14:00:00', 50.00, 'accepted', NULL, 0, NULL, NULL, 'ZUS Coffee, Avenue 5, Bangsar South, Pantai Dalam, Kuala Lumpur, 59200, Malaysia', '2026-05-22 14:42:14', '', 'Reading', NULL, NULL, 0, 'beginner'),
(36, 4, 3, 'Mandarin', 'online', '2026-05-30', '13:00:00', 50.00, 'cancelled', NULL, 0, 'student', 'Change of plans', NULL, '2026-05-23 13:30:43', '', 'Speaking', NULL, NULL, 0, 'beginner'),
(37, 4, 3, 'Mandarin', 'online', '2026-05-30', '14:00:00', 50.00, 'confirmed', NULL, 0, NULL, NULL, NULL, '2026-05-23 13:44:08', '', 'Listening', 'https://meet.google.com/uev-betk-yup', NULL, 0, 'beginner'),
(38, 4, 10, 'English', 'online', '2026-05-29', '06:00:00', 47.00, 'pending', NULL, 0, NULL, NULL, NULL, '2026-05-23 14:00:15', '', 'Speaking', NULL, NULL, 0, 'beginner');

-- --------------------------------------------------------

--
-- Table structure for table `disputes`
--

CREATE TABLE `disputes` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `issue_type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('pending','resolved','rejected') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `learning_materials`
--

CREATE TABLE `learning_materials` (
  `id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
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
  `proficiency_level` varchar(20) DEFAULT 'beginner'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `learning_materials`
--

INSERT INTO `learning_materials` (`id`, `tutor_id`, `booking_id`, `title`, `description`, `feedback`, `file_name`, `file_path`, `material_url`, `is_url`, `file_type`, `file_size`, `uploaded_at`, `material_type`, `proficiency_level`) VALUES
(2, 3, 37, 'Self Introduction', '', NULL, '', '', 'https://youtu.be/McZW0iDsZns?si=thX0_WIP6M2xXOU9', 1, 'url', 0, '2026-05-24 13:55:52', 'pre', 'beginner'),
(4, 3, 37, 'Lesson 1 Note', '', NULL, 'Lesson 1 Note.pdf', '../uploads/materials/1779602681_ddc81c0f608768ff.pdf', NULL, 0, 'application/pdf', 277557, '2026-05-24 14:04:41', 'post', 'beginner');

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
(22, 2, 'Booking Accepted! 🎉', 'Your English session with Feng Xi on Friday, May 29, 2026 at 2:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=35', 0, '2026-05-22 23:47:57'),
(23, 2, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 6:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=33', 0, '2026-05-23 00:00:00'),
(24, 2, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 3:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=32', 0, '2026-05-23 00:05:42'),
(26, 2, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 27 May 2026 at 2:00 PM', 'reschedule', NULL, 0, '2026-05-23 19:34:07'),
(29, 3, 'Booking Cancelled', 'Sarah cancelled their Mandarin lesson on Saturday, 30 May 2026 at 1:00 PM. Reason: Change of plans', 'booking_cancelled', 'booking_detail.php?id=36', 0, '2026-05-23 21:43:35'),
(30, 4, 'Booking Cancelled', 'You have cancelled your Mandarin lesson with Feng Xi on Saturday, 30 May 2026 at 1:00 PM. Reason: Change of plans', 'booking_cancelled', 'booking_status.php', 0, '2026-05-23 21:43:35'),
(31, 4, 'Booking Accepted! 🎉', 'Your Mandarin session with Feng Xi on Saturday, May 30, 2026 at 1:00 PM has been accepted. Please proceed to payment.', 'booking_accepted', 'booking_detail.php?id=37', 0, '2026-05-23 22:06:12'),
(32, 3, 'New Reschedule Request', 'Student has requested to reschedule a session.', 'reschedule', NULL, 0, '2026-05-23 22:33:10'),
(33, 4, 'Reschedule Request Approved', 'Your tutor has approved your reschedule request. New date: 30 May 2026 at 2:00 PM', 'reschedule', NULL, 0, '2026-05-23 22:36:55'),
(34, 2, 'Meeting Link Added!', 'Your tutor has added the meeting link for your English session on Monday, May 25, 2026 at 12:00 PM.', 'meeting_link_updated', 'booking_detail.php?id=30', 0, '2026-05-24 13:34:27'),
(35, 4, 'New Learning Material', 'Feng Xi uploaded: Self Introduction', 'learning_materials.php?booking', NULL, 0, '2026-05-24 13:55:52'),
(36, 4, 'New Learning Material', 'Feng Xi uploaded: Self Introduction', 'learning_materials.php?booking', NULL, 0, '2026-05-24 13:56:03'),
(37, 4, 'New Learning Material', 'Feng Xi uploaded: Lesson 1 Note', 'learning_materials.php?booking', NULL, 0, '2026-05-24 14:04:41');

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
  `status` enum('pending','verified','failed','cancelled','disputed') DEFAULT 'pending',
  `receipt_number` varchar(20) DEFAULT NULL,
  `receipt_url` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `booking_id`, `student_id`, `tutor_id`, `amount`, `payment_method`, `status`, `receipt_number`, `receipt_url`, `notes`, `proof_image`, `created_at`) VALUES
(1, 2, 2, 10, 47.00, 'online_banking', 'disputed', NULL, NULL, '\n[2026-05-19 23:20:56] Student reported: Money deducted but payment shows failed. Amount: RM47', NULL, '2026-05-09 18:59:26'),
(2, 1, 2, 5, 45.00, 'stripe', 'verified', NULL, NULL, NULL, NULL, '2026-05-10 12:21:22'),
(3, 20, 2, 8, 46.00, 'stripe', 'verified', NULL, NULL, NULL, NULL, '2026-05-17 16:30:37'),
(4, 24, 2, 10, 47.00, 'online_banking', 'pending', 'RCP-2026-029876', NULL, 'Transferred by FPX', 'proof_24_1779042604.png', '2026-05-17 18:30:04'),
(5, 30, 2, 3, 50.00, 'stripe', 'verified', 'RCP-2026-087463', NULL, NULL, NULL, '2026-05-21 04:14:00'),
(6, 28, 2, 3, 50.00, 'stripe', 'verified', 'pi_3TZOVlAjFaJboEti1', 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKNGcutAGMgaijAHY6uI6LBYhhT2HOkmpzEHms_R0LMaagn4e0NLapRmjdVOuhc72hOoUXOw6IRPyD4bV', NULL, NULL, '2026-05-21 04:47:13'),
(7, 37, 4, 3, 50.00, 'stripe', 'verified', 'pi_3TaGFkAjFaJboEti1', 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xVFZWSFBBakZhSmJvRXRpKMjqxtAGMgbS5KKDl5E6LBapz65LC1iIk5EJl8Fo_DsCS2_6ViuPvvKzNBqc4PlPYYY9oqFHunWBRavY', NULL, NULL, '2026-05-23 14:10:15');

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
(3, 7, 4, 9, 5, 'Farah is amazing, very friendly and clear explanation.', 0, '2026-05-09 07:25:23'),
(8, 11, 2, 3, 5, 'Good class', 1, '2026-05-17 08:31:39'),
(9, 13, 2, 3, 3, '', 1, '2026-05-17 09:08:42'),
(10, 10, 2, 3, 4, '', 1, '2026-05-17 09:08:54'),
(11, 12, 2, 3, 5, 'Good class', 1, '2026-05-18 04:08:58');

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
(1, 1, 2, 5, '2026-05-28', '12:00:00', '2026-05-13', '16:00:00', 'Japanese', 'online', 'Writing', '', NULL, 'pending', '2026-05-10 15:00:49', '2026-05-10 15:00:49', NULL, NULL),
(2, 17, 2, 3, '2026-05-28', '12:00:00', '2026-05-27', '14:00:00', 'Mandarin', 'online', 'Speaking', '', NULL, 'approved', '2026-05-17 07:05:15', '2026-05-23 11:34:17', NULL, '2026-05-23 19:34:17'),
(6, 20, 2, 8, '2026-06-13', '12:00:00', '2026-05-30', '14:00:00', 'Korean', 'online', 'Listening, Reading', '', NULL, 'pending', '2026-05-19 08:57:09', '2026-05-19 08:57:09', NULL, NULL),
(7, 37, 4, 3, '2026-05-30', '13:00:00', '2026-05-30', '14:00:00', 'Mandarin', 'online', 'Writing', '', '', 'approved', '2026-05-23 14:33:10', '2026-05-23 14:36:55', NULL, '2026-05-23 22:36:55');

-- --------------------------------------------------------

--
-- Table structure for table `session_attendance`
--

CREATE TABLE `session_attendance` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `attended` tinyint(4) DEFAULT 0,
  `attendance_date` date NOT NULL,
  `marked_by` enum('student','tutor','auto') DEFAULT 'auto',
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `session_completion`
--

INSERT INTO `session_completion` (`id`, `booking_id`, `tutor_confirmed`, `student_confirmed`, `tutor_confirmed_at`, `student_confirmed_at`, `completed_at`) VALUES
(1, 6, 1, 1, NULL, NULL, '2026-05-20 00:19:29'),
(2, 21, 1, 1, NULL, '2026-05-20 03:46:08', '2026-05-20 02:00:01'),
(4, 7, 1, 1, NULL, NULL, '2026-05-21 11:01:09'),
(5, 9, 1, 1, NULL, NULL, '2026-05-21 11:01:09'),
(6, 10, 1, 1, NULL, NULL, '2026-05-21 11:01:09'),
(7, 11, 1, 1, NULL, NULL, '2026-05-21 11:01:09'),
(8, 12, 1, 1, NULL, NULL, '2026-05-21 11:01:09'),
(9, 13, 1, 1, NULL, NULL, '2026-05-21 11:01:09');

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
(27, 4, 3, '2026-05-23 13:09:48');

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
(57, 2, 'online'),
(58, 2, 'face_to_face'),
(59, 2, 'online');

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
(68, 12, 'Mandarin'),
(81, 2, 'Mandarin'),
(82, 2, 'English'),
(83, 2, 'Japanese');

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
-- Table structure for table `tutor_feedback`
--

CREATE TABLE `tutor_feedback` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `tutor_id` int(11) NOT NULL,
  `feedback` text DEFAULT NULL,
  `rating` int(11) DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `areas_to_improve` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `is_verified` tinyint(1) DEFAULT 0,
  `proficiency_level` varchar(20) DEFAULT 'beginner'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `password`, `phone`, `role`, `profile_pic`, `created_at`, `status`, `verification_token`, `is_verified`, `proficiency_level`) VALUES
(1, 'Ali', 'ali@gmail.com', '$2y$10$BFTMIbbp0RhnxUkRdHmG9.BLGGhcNJAXu00jolOoVLWEYF0Et7j9O', NULL, 'admin', NULL, '2026-05-06 06:38:20', 'approved', NULL, 0, 'beginner'),
(2, 'Sharon', 'morasharon790@gmail.com', '$2y$10$Do.N5c5VZNnvsTuaS8vhR.JIKUmh66I0CGh0ArPX/jdzakKSg3.Ry', '01155532488', 'student', 'student_2_1779222498.png', '2026-05-06 09:16:55', 'approved', NULL, 0, 'beginner'),
(3, 'Feng Xi', 'fengxiii87@gmail.com', '$2y$10$LiwtHaYor.N79M.LSGZzfOOXwSy3YWmlBaV0stZUUpmRtYj1l4qPy', '0123456789', 'tutor', 'fengxi.jpg', '2026-05-06 13:03:13', 'approved', NULL, 0, 'beginner'),
(4, 'Sarah', 'sarah@gmail.com', '$2y$10$pto8/27JofkiIgpqJRgDeeRPGtV0TQIjv/mzPTl3rAHfMAly69Wau', '0123456701', 'student', '', '2026-05-07 05:59:23', 'approved', NULL, 0, 'beginner'),
(5, 'Haruka Tan', 'haruka@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456781', 'tutor', 'haruka.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 'beginner'),
(6, 'Daniel Lee', 'daniel@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456782', 'tutor', 'daniel.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 'beginner'),
(7, 'Alicia Wong', 'alicia@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456783', 'tutor', 'alicia.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 'beginner'),
(8, 'Kim Jisoo', 'kimjisoo@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456784', 'tutor', 'kimjisoo.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 'beginner'),
(9, 'Farah Nabila', 'farah@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456785', 'tutor', 'farah.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 'beginner'),
(10, 'Aina Yusuf', 'aina@kyoshi.com', '$2y$10$9.IAgJjqSWzoXeaSKgTKtehJx8sLiBz4Fy3ft..EyUCkcYSVJ9xRa', '0123456786', 'tutor', 'aina.jpg', '2026-05-08 04:14:30', 'approved', NULL, 0, 'beginner'),
(11, 'Kay', 'kay@gmail.com', '$2y$10$Ky3faHhz7Zh4YviWQwDrw.KzGT.X3F9HhIVftf8mN/WNa5K7oEeyi', '', 'student', '', '2026-05-11 14:36:20', 'approved', '9d0a2437b772fe9c8e4f1532a1dfb28df627cb22902ee5a0d0998dab112677ac', 0, 'beginner'),
(12, 'Sia', 'chinming0210@gmail.com', '$2y$10$.f4052SpkFwpGCDOGYr8yOfcK3u7RycxnobH/NCYYuqHvAtpSv4Ta', '', 'student', '', '2026-05-18 03:30:12', 'approved', '7aaea97bc148c358f33502ef5eb882da23e96482a2c47d104fa6255c7668ff5f', 0, 'beginner');

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
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`),
  ADD KEY `idx_booking` (`booking_id`);

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
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tutor_id` (`tutor_id`),
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
-- Indexes for table `session_attendance`
--
ALTER TABLE `session_attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking` (`booking_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `session_completion`
--
ALTER TABLE `session_completion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

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
-- Indexes for table `tutor_feedback`
--
ALTER TABLE `tutor_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking` (`booking_id`);

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
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `disputes`
--
ALTER TABLE `disputes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `learning_materials`
--
ALTER TABLE `learning_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `reschedule_requests`
--
ALTER TABLE `reschedule_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `session_attendance`
--
ALTER TABLE `session_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `session_completion`
--
ALTER TABLE `session_completion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `student_favourites`
--
ALTER TABLE `student_favourites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `student_learning_modes`
--
ALTER TABLE `student_learning_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `student_preferences`
--
ALTER TABLE `student_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

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
-- AUTO_INCREMENT for table `tutor_feedback`
--
ALTER TABLE `tutor_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `disputes`
--
ALTER TABLE `disputes`
  ADD CONSTRAINT `disputes_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disputes_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD CONSTRAINT `learning_materials_ibfk_1` FOREIGN KEY (`tutor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `learning_materials_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `session_attendance`
--
ALTER TABLE `session_attendance`
  ADD CONSTRAINT `session_attendance_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `session_completion`
--
ALTER TABLE `session_completion`
  ADD CONSTRAINT `session_completion_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`);

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
-- Constraints for table `tutor_feedback`
--
ALTER TABLE `tutor_feedback`
  ADD CONSTRAINT `tutor_feedback_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

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
