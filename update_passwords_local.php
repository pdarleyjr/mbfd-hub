<?php
// Generate bcrypt hashes for the VPS passwords
echo '$2y$12$'.crypt('Penco1', '$2y$12$'.str_repeat('a', 22))."\n";
echo password_hash('Penco1', PASSWORD_BCRYPT)."\n";
echo password_hash('Penco2', PASSWORD_BCRYPT)."\n";
echo password_hash('Penco3', PASSWORD_BCRYPT)."\n";
echo password_hash('MBFDGerry1', PASSWORD_BCRYPT)."\n";
