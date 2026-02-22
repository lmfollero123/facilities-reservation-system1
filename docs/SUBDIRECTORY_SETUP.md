# Running from a Subdirectory (e.g. htdocs/facilities_reservation_system1)

If you're testing the app from a subdirectory (e.g. `C:\xampp\htdocs\facilities_reservation_system1\`) instead of a virtual host:

## URL to use

```
http://localhost/facilities_reservation_system1/
```

Replace `facilities_reservation_system1` with your actual folder name.

## If links go to wrong URLs (e.g. `localhost/dashboard` instead of `localhost/facilities_reservation_system1/dashboard`)

1. **Pull the latest code** – `base_path()` was updated to detect subdirectory installs automatically.
2. **Hard refresh** (Ctrl+F5) to clear cached HTML.
3. **Restart Apache** – after pulling, restart XAMPP Apache in case opcode cache is serving old PHP.

## Troubleshooting

- **404 on Login/Home/FAQs**: Ensure you pulled the latest `config/app.php` (base_path fix) and `.htaccess` (RewriteBase removed for subdir support).
- **Styles not loading**: Same as above – asset paths use `base_path()`.
- **Preferred setup**: Use a virtual host (see [XAMPP_VIRTUAL_HOST_SETUP.md](XAMPP_VIRTUAL_HOST_SETUP.md)) so the app runs at `lgu.test` like production.
