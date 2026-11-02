<?php
/** Database tables. Used by install.php; safe to run again (CREATE TABLE IF NOT EXISTS). */
defined('APP_DIR') || exit;

return [
    "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS teams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(9) NOT NULL DEFAULT '#e53935',
        leader_name VARCHAR(100) NOT NULL DEFAULT '',
        leader_phone VARCHAR(30) NOT NULL DEFAULT '',
        pin_hash VARCHAR(255) NOT NULL DEFAULT '',
        member_pin_hash VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'not_started',
        started_at INT NULL,
        returned_at INT NULL,
        expected_return_at INT NULL,
        notes TEXT,
        created_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS cars (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NULL,
        label VARCHAR(60) NOT NULL,
        plate VARCHAR(30) NOT NULL DEFAULT '',
        driver_name VARCHAR(100) NOT NULL DEFAULT '',
        driver_phone VARCHAR(30) NOT NULL DEFAULT '',
        created_at INT NOT NULL,
        KEY idx_team (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(64) NOT NULL,
        token VARCHAR(40) NOT NULL,
        name VARCHAR(100) NOT NULL DEFAULT '',
        team_id INT NULL,
        car_id INT NULL,
        source VARCHAR(10) NOT NULL DEFAULT 'traccar',
        role VARCHAR(10) NOT NULL DEFAULT '',
        person_phone VARCHAR(30) NOT NULL DEFAULT '',
        app_seen_at INT NULL,
        approved TINYINT(1) NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        last_lat DOUBLE NULL,
        last_lng DOUBLE NULL,
        last_speed FLOAT NULL,
        last_bearing FLOAT NULL,
        last_accuracy FLOAT NULL,
        last_battery FLOAT NULL,
        last_charging TINYINT(1) NULL,
        last_fix_at INT NULL,
        last_seen_at INT NULL,
        created_at INT NOT NULL,
        UNIQUE KEY uq_identifier (identifier),
        UNIQUE KEY uq_token (token),
        KEY idx_team (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS positions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        lat DOUBLE NOT NULL,
        lng DOUBLE NOT NULL,
        speed FLOAT NULL,
        bearing FLOAT NULL,
        accuracy FLOAT NULL,
        battery FLOAT NULL,
        fix_at INT NOT NULL,
        received_at INT NOT NULL,
        KEY idx_device_time (device_id, fix_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS stations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        area VARCHAR(100) NOT NULL DEFAULT '',
        address VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        maps_link TEXT,
        lat DOUBLE NULL,
        lng DOUBLE NULL,
        team_id INT NULL,
        suggested_order INT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        reached_at INT NULL,
        reached_by VARCHAR(10) NULL,
        done_at INT NULL,
        done_by VARCHAR(10) NULL,
        near_since INT NULL,
        near_device_id INT NULL,
        hold_until INT NULL,
        boxes INT NULL,
        notes TEXT,
        active TINYINT(1) NOT NULL DEFAULT 1,
        shared VARCHAR(5) NOT NULL DEFAULT '',
        created_at INT NOT NULL,
        updated_at INT NOT NULL,
        KEY idx_team (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Each team's visit to a station that every team goes to (stations.shared = 'first' or 'last').
    "CREATE TABLE IF NOT EXISTS station_visits (
        station_id INT NOT NULL,
        team_id INT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        reached_at INT NULL,
        reached_by VARCHAR(10) NULL,
        done_at INT NULL,
        done_by VARCHAR(10) NULL,
        near_since INT NULL,
        near_device_id INT NULL,
        hold_until INT NULL,
        skip TINYINT(1) NOT NULL DEFAULT 0,
        updated_at INT NOT NULL DEFAULT 0,
        PRIMARY KEY (station_id, team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        scope VARCHAR(40) NOT NULL,
        at INT NOT NULL,
        KEY idx_ip (ip, at),
        KEY idx_scope (scope, at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(30) NOT NULL,
        team_id INT NULL,
        station_id INT NULL,
        device_id INT NULL,
        message VARCHAR(255) NOT NULL,
        created_at INT NOT NULL,
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
