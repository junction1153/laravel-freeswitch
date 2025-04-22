#!/bin/bash

# Function to print success message
print_success() {
    echo -e "\e[32m$1 \e[0m"  # Green text
}

# Function to print error message
print_error() {
    echo -e "\e[31m$1 \e[0m"  # Red text
}

# Function to check if a command was successful
check_command() {
    if [ $? -eq 0 ]; then
        print_success "$1"
    else
        print_error "$2"
        exit 1
    fi
}

# Backup the sudoers file
SUDOERS_FILE="/etc/sudoers"
BACKUP_SUDOERS="/etc/sudoers.bak"
SUDOERS_TEMP=$(mktemp)
IPTABLES_PERMISSION="www-data ALL=(ALL) NOPASSWD: /sbin/iptables"
FAIL2BAN_PERMISSION="www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client"
IPTABLES_SAVE_PERMISSION="www-data ALL=(ALL) NOPASSWD: /sbin/iptables-save"
SUPERVISORCTL_PERMISSION="www-data ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl"


if [ -f "$SUDOERS_FILE" ]; then
    sudo cp "$SUDOERS_FILE" "$BACKUP_SUDOERS"
    check_command "Sudoers file backed up successfully." "Failed to backup sudoers file."
else
    print_error "Sudoers file not found."
    exit 1
fi

# Function to add permission to sudoers file if not already present
add_permission() {
    local PERMISSION=$1

    # Check if the permission is already present
    if ! sudo grep -qF "$PERMISSION" "$SUDOERS_FILE"; then
        # Add the permission if not present
        sudo cat "$SUDOERS_FILE" > "$SUDOERS_TEMP"
        echo "$PERMISSION" | sudo tee -a "$SUDOERS_TEMP" > /dev/null

        # Validate the new sudoers file
        sudo visudo -c -f "$SUDOERS_TEMP"
        if [ $? -eq 0 ]; then
            sudo cp "$SUDOERS_TEMP" "$SUDOERS_FILE"
            check_command "Sudoers file updated successfully with permission: $PERMISSION" "Failed to update sudoers file with permission: $PERMISSION"
        else
            print_error "The sudoers file update failed validation for permission: $PERMISSION. The original file has been preserved."
            exit 1
        fi
    else
        print_success "Permission already exists in the sudoers file: $PERMISSION. No changes made."
    fi
}

# Add iptables, fail2ban-client, and iptables-save permissions
add_permission "$IPTABLES_PERMISSION"
add_permission "$FAIL2BAN_PERMISSION"
add_permission "$IPTABLES_SAVE_PERMISSION"
add_permission "$SUPERVISORCTL_PERMISSION"

# Cleanup temporary files
rm "$SUDOERS_TEMP"

print_success "Web server user now allowed to execute iptables, iptables-save, fail2ban-client, and supervisorctl commands without a password!"
