#!/bin/bash
echo "ALTER USER 'root'@'localhost' IDENTIFIED BY 'eve-ng';" | mysql &> /dev/null
echo "CREATE USER IF NOT EXISTS 'eve-ng'@'localhost' IDENTIFIED BY 'eve-ng';" | mysql --host=localhost --user=root --password=eve-ng &> /dev/null
if [ $? -ne 0 ]; then
        echo -e "\033[0;31mfailed\033[0m"
        exit 1
fi
echo "GRANT ALL ON eve_ng_db.* TO 'eve-ng'@'localhost';" | mysql --host=localhost --user=root --password=eve-ng &> /dev/null
if [ $? -ne 0 ]; then
        echo -e "\033[0;31mfailed\033[0m"
        exit 1
fi
echo "\q" | mysql -u root --password=eve-ng guacdb &> /dev/null
if [ $? -ne 0 ]; then
        echo -ne "Creating database and users... "
        echo "CREATE DATABASE IF NOT EXISTS guacdb;" | mysql --host=localhost --user=root --password=eve-ng &> /dev/null
        if [ $? -ne 0 ]; then
                echo -e "\033[0;31mfailed\033[0m"
                exit 1
        fi
        echo "CREATE USER IF NOT EXISTS 'guacuser'@'localhost' IDENTIFIED BY 'eve-ng';" | mysql --host=localhost --user=root --password=eve-ng &> /dev/null
        if [ $? -ne 0 ]; then
                echo -e " create user \033[0;31mfailed\033[0m"
                exit 1
        fi
        echo "GRANT ALL ON guacdb.* TO 'guacuser'@'localhost';" | mysql --host=localhost --user=root --password=eve-ng &> /dev/null
        if [ $? -ne 0 ]; then
                echo -e " grant \033[0;31mfailed\033[0m"
                exit 1
        fi
        cat /opt/unetlab/schema/guacamole-1.0-*.sql | mysql --host=localhost --user=root --password=eve-ng guacdb &> /dev/null
        if [ $? -ne 0 ]; then
                echo -e " shema \033[0;31mfailed\033[0m"
                exit 1
        fi
        echo "SET @salt = UNHEX(SHA2(UUID(), 256)); UPDATE guacamole_user SET password_salt = @salt, password_hash = UNHEX(SHA2(CONCAT('eve-ng', HEX(@salt)), 256)) WHERE user_id =  1 ;" | mysql --user=root --password=eve-ng guacdb &> /dev/null
        if [ $? -ne 0 ]; then
                echo -e "set password \033[0;31mfailed\033[0m"
                exit 1
        fi

        echo -e "\033[0;32mdone\033[0m"
fi
