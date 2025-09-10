-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Creato il: Set 10, 2025 alle 19:15
-- Versione del server: 10.6.23-MariaDB-cll-lve
-- Versione PHP: 8.4.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bwlxtuul_dojo`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `selector` varchar(32) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `tasks`
--

CREATE TABLE `tasks` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(180) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('bassa','media','alta','urgente') NOT NULL DEFAULT 'media',
  `dipartimento` enum('Amministrazione','Reception','Booking','Manutenzione','Bar','HouseKeeping') NOT NULL,
  `due_date` date NOT NULL,
  `recurrence` enum('nessuna','giornaliera','settimanale','mensile','annuale') NOT NULL DEFAULT 'nessuna',
  `status` enum('aperto','completato','non_fattibile') NOT NULL DEFAULT 'aperto',
  `status_note` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `completed_by` int(10) UNSIGNED DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `not_feasible_by` int(10) UNSIGNED DEFAULT NULL,
  `not_feasible_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `tasks`
--

INSERT INTO `tasks` (`id`, `title`, `description`, `priority`, `dipartimento`, `due_date`, `recurrence`, `status`, `status_note`, `created_by`, `completed_by`, `completed_at`, `not_feasible_by`, `not_feasible_at`, `created_at`, `deleted_at`) VALUES
(1, 'Verniciatura ringhiera', 'Verniciare la ringhiera del garage dove c\'era la pianta che abbiamo tagliato. La pianta strofinava e la pittura è andata via', 'bassa', 'Manutenzione', '2025-11-09', 'nessuna', 'aperto', NULL, 1, NULL, NULL, NULL, NULL, '2025-09-09 19:17:45', NULL),
(2, 'Ripristino Soffitto Vetro Garage', 'Ripristinare l\'apertura in vetro logorata con ripristino del ferro di armatura laterale e le angoliere', 'media', 'Manutenzione', '2025-11-09', 'nessuna', 'aperto', NULL, 1, NULL, NULL, NULL, NULL, '2025-09-09 19:39:10', NULL),
(3, 'Ripristino Angoliere n°10', 'Ripristinare le angoliere del in muratura del numero 10 nella veranda', 'media', 'Manutenzione', '2025-11-09', 'nessuna', 'aperto', NULL, 1, NULL, NULL, NULL, NULL, '2025-09-09 19:43:54', NULL),
(4, 'Sfiato Fogna Villette', 'Montare lo sfiato della fogna al tubo del 24', 'media', 'Manutenzione', '2025-11-09', 'nessuna', 'aperto', NULL, 1, NULL, NULL, NULL, NULL, '2025-09-09 19:46:15', NULL),
(5, 'Compito di prova', 'test', 'urgente', 'Amministrazione', '2025-09-09', 'giornaliera', 'completato', NULL, 1, 1, '2025-09-09 21:52:16', NULL, NULL, '2025-09-09 19:51:44', '2025-09-09 21:53:43'),
(6, 'Compito di prova', 'test', 'urgente', 'Amministrazione', '2025-09-10', 'giornaliera', 'completato', NULL, 1, 1, '2025-09-09 21:52:26', NULL, NULL, '2025-09-09 19:52:16', '2025-09-09 21:53:36'),
(7, 'Compito di prova', 'test', 'urgente', 'Amministrazione', '2025-09-11', 'giornaliera', 'non_fattibile', 'stavo male', 1, NULL, NULL, 1, '2025-09-09 21:52:39', '2025-09-09 19:52:26', '2025-09-09 21:53:30');

-- --------------------------------------------------------

--
-- Struttura della tabella `transfers_external`
--

CREATE TABLE `transfers_external` (
  `id` int(10) UNSIGNED NOT NULL,
  `type` enum('arrivo','partenza') NOT NULL,
  `place` enum('Aeroporto Lamezia Terme','Aeroporto Reggio Calabria','Stazione Lamezia Terme','Stazione Rosarno') NOT NULL DEFAULT 'Aeroporto Lamezia Terme',
  `date_time` datetime NOT NULL,
  `pickup_time` time DEFAULT NULL,
  `room_number` varchar(20) NOT NULL,
  `guest_name` varchar(120) NOT NULL,
  `booked` tinyint(1) NOT NULL DEFAULT 0,
  `paid` tinyint(1) NOT NULL DEFAULT 0,
  `service_company` varchar(120) DEFAULT NULL,
  `status` enum('attivo','annullato') NOT NULL DEFAULT 'attivo',
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `transfers_external`
--

INSERT INTO `transfers_external` (`id`, `type`, `place`, `date_time`, `pickup_time`, `room_number`, `guest_name`, `booked`, `paid`, `service_company`, `status`, `created_by`, `created_at`, `deleted_at`) VALUES
(1, 'partenza', 'Aeroporto Lamezia Terme', '2025-09-09 10:00:00', '07:00:00', '9', 'La Mantia', 1, 0, 'DanyExpress', 'attivo', 1, '2025-09-09 20:43:21', '2025-09-10 08:50:07'),
(2, 'partenza', 'Aeroporto Lamezia Terme', '2025-09-16 10:00:00', '07:00:00', '9', 'La Mantia', 1, 0, NULL, 'attivo', 1, '2025-09-10 06:21:34', NULL),
(3, 'arrivo', 'Aeroporto Lamezia Terme', '2025-09-16 14:15:00', NULL, '9', 'Stadler', 1, 0, 'DanyExpress', 'attivo', 1, '2025-09-10 06:49:10', NULL),
(4, 'partenza', 'Aeroporto Lamezia Terme', '2025-09-23 14:40:00', '11:40:00', '9', 'Stadler', 1, 0, 'DanyExpress', 'attivo', 1, '2025-09-10 15:42:53', NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `transfers_internal`
--

CREATE TABLE `transfers_internal` (
  `id` int(10) UNSIGNED NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `direction` enum('da','per') NOT NULL,
  `location` varchar(120) NOT NULL,
  `when_at` datetime NOT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `transfers_internal`
--

INSERT INTO `transfers_internal` (`id`, `room_number`, `direction`, `location`, `when_at`, `created_by`, `created_at`, `deleted_at`) VALUES
(1, '12', 'per', 'Coop', '2025-09-09 02:45:00', 1, '2025-09-09 20:41:34', NULL);

-- --------------------------------------------------------

--
-- Struttura della tabella `transfers_internal_blocks`
--

CREATE TABLE `transfers_internal_blocks` (
  `id` int(10) UNSIGNED NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `note` varchar(180) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','editor') NOT NULL DEFAULT 'editor',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `nome` varchar(100) NOT NULL DEFAULT '',
  `cognome` varchar(100) NOT NULL DEFAULT '',
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `dipartimento` enum('Amministrazione','Reception','Booking','Manutenzione','Bar','HouseKeeping') NOT NULL DEFAULT 'Amministrazione'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `deleted_at`, `nome`, `cognome`, `telefono`, `dipartimento`) VALUES
(1, 'simone@villaggiotramonto.it', '$2y$10$mFaWKILO1CAsQx65UCluX.pWkBayQ/UhjoPPBKjXk8COY//gHdyca', 'admin', 1, '2025-09-08 14:14:31', NULL, 'Simone', 'Tocco', '+393341913800', 'Amministrazione'),
(2, 'booking@villaggiotramonto.it', '$2y$10$p5bkdUh6DCsj4JCXyu8aKOZQmv9rXUr1SByQAiyC6ruVbA9eEGBYS', 'editor', 1, '2025-09-09 10:46:03', NULL, 'Benedetta', 'Almaviva', '+393289608182', 'Amministrazione');

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`);

--
-- Indici per le tabelle `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dipartimento` (`dipartimento`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `status` (`status`),
  ADD KEY `fk_tasks_user` (`created_by`);

--
-- Indici per le tabelle `transfers_external`
--
ALTER TABLE `transfers_external`
  ADD PRIMARY KEY (`id`),
  ADD KEY `date_time` (`date_time`),
  ADD KEY `room_number` (`room_number`),
  ADD KEY `fk_tr_ext_user` (`created_by`);

--
-- Indici per le tabelle `transfers_internal`
--
ALTER TABLE `transfers_internal`
  ADD PRIMARY KEY (`id`),
  ADD KEY `when_at` (`when_at`),
  ADD KEY `room_number` (`room_number`),
  ADD KEY `fk_tr_int_user` (`created_by`);

--
-- Indici per le tabelle `transfers_internal_blocks`
--
ALTER TABLE `transfers_internal_blocks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `start_at` (`start_at`),
  ADD KEY `end_at` (`end_at`),
  ADD KEY `fk_tr_blk_user` (`created_by`);

--
-- Indici per le tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT per la tabella `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT per la tabella `transfers_external`
--
ALTER TABLE `transfers_external`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `transfers_internal`
--
ALTER TABLE `transfers_internal`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT per la tabella `transfers_internal_blocks`
--
ALTER TABLE `transfers_internal_blocks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_tasks_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `transfers_external`
--
ALTER TABLE `transfers_external`
  ADD CONSTRAINT `fk_tr_ext_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `transfers_internal`
--
ALTER TABLE `transfers_internal`
  ADD CONSTRAINT `fk_tr_int_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `transfers_internal_blocks`
--
ALTER TABLE `transfers_internal_blocks`
  ADD CONSTRAINT `fk_tr_blk_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
