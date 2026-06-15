#!/bin/bash
# MyanAi Demo Tenant Auto-Reset
# Runs daily via cron — resets demo tenant (id=9) data
# Add to crontab: 0 2 * * * /var/www/myanai/demo_reset_cron.sh >> /var/log/myanai_demo_reset.log 2>&1

LOG="/var/log/myanai_demo_reset.log"
echo "=== Demo reset started: $(date) ===" >> $LOG

# Run seed script via PHP CLI
php8.5 /var/www/myanai/demo_seed.php cli_key=myanai_seed_2026 2>&1 | tee -a $LOG

echo "=== Demo reset done: $(date) ===" >> $LOG
