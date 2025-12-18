@echo off
cd /d "E:\Capstone_project\facilities_reservation_system"
"C:\xampp\php\php.exe" "E:\Capstone_project\facilities_reservation_system\scripts\auto_decline_expired.php"  >> "E:\Capstone_project\facilities_reservation_system\storage\task_logs\FRS_-_Auto-Decline_Expired_Reservations__Daily_.log" 2>&1
