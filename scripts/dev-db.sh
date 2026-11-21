#!/usr/bin/env bash
# Әзірлеу ортасындағы жергілікті MariaDB-ді іске қосады (жұмыс істеп тұрмаса) және
# сынақ дерекқорларын дайындайды. Тек әзірлеуге арналған, серверде қолданылмайды.
set -euo pipefail
if ! mariadb -uroot -e "select 1" >/dev/null 2>&1; then
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
  nohup setsid mariadbd-safe --user=mysql >/tmp/mariadb.log 2>&1 &
  for _ in $(seq 1 30); do
    mariadb -uroot -e "select 1" >/dev/null 2>&1 && break
    sleep 1
  done
fi
mariadb -uroot <<'SQL'
create database if not exists qatysu_dev character set utf8mb4 collate utf8mb4_unicode_ci;
create database if not exists qatysu_test character set utf8mb4 collate utf8mb4_unicode_ci;
create user if not exists 'qatysu'@'127.0.0.1' identified by 'devonly';
create user if not exists 'qatysu'@'localhost' identified by 'devonly';
grant all on qatysu_dev.* to 'qatysu'@'127.0.0.1';
grant all on qatysu_test.* to 'qatysu'@'127.0.0.1';
grant all on qatysu_dev.* to 'qatysu'@'localhost';
grant all on qatysu_test.* to 'qatysu'@'localhost';
SQL
mariadb -uroot -N -e "select concat('MariaDB ', version())"
