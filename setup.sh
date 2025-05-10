#!/bin/bash
# BITLOCK Easy Installer
echo "Welcome to BITLOCK Installer"
echo "Setting up your secure file management system..."

# Check if running as root
if [ "$EUID" -ne 0 ]; then
  echo "Please run as root (use sudo)"
  exit
fi

# Install dependencies
apt-get update
apt-get install -y apache2 php php-json

# Create directory
mkdir -p /var/www/html/bit_lock

# Download latest version
wget -O /var/www/html/bitlock/index.php https://github.com/TH3ONU5/BIT_LOCK

# Set permissions
chown -R www-data:www-data /var/www/html/bit_lock
chmod -R 755 /var/www/html/bit_lock

echo "Installation complete! Access BITLOCK at: http://YOUR_SERVER_IP/bitlock"
