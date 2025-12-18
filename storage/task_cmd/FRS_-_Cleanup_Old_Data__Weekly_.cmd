@echo off
cd /d "E:\Capstone_project\facilities_reservation_system"
"C:\xampp\php\php.exe" "E:\Capstone_project\facilities_reservation_system\scripts\cleanup_old_data.php"  >> "E:\Capstone_project\facilities_reservation_system\storage\task_logs\FRS_-_Cleanup_Old_Data__Weekly_.log" 2>&1
