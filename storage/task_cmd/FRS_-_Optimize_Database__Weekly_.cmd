@echo off
cd /d "E:\Capstone_project\facilities_reservation_system"
"C:\xampp\php\php.exe" "E:\Capstone_project\facilities_reservation_system\scripts\optimize_database.php"  >> "E:\Capstone_project\facilities_reservation_system\storage\task_logs\FRS_-_Optimize_Database__Weekly_.log" 2>&1
