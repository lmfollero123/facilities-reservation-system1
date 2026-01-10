<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Repair Request - LGU Citizen Portal</title>
    <link rel="stylesheet" href="style - Copy.css">
    <style>
        /* PAGE STYLING */
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            /* Ensure background is attached to body */
            background: url("cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }

        /* FIXED BLUR OVERLAY */
        body::before {
            content: "";
            position: fixed; /* Changed to fixed to cover the whole viewport during scroll */
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            backdrop-filter: blur(8px); /* Adjust blur strength here */
            background: rgba(0, 0, 0, 0.4); /* Darker overlay for better text contrast */
            z-index: -1; /* Place behind all content */
        }

        /* NAVBAR - styled like Employee sidebar */
        .nav {
            width: 100%;
            padding: 18px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            
            background: rgba(255, 255, 255, 0.15);     /* softer glass */
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);

            border-bottom: 1px solid rgba(255, 255, 255, 0.25);  /* glowing border */
            box-shadow: 0 4px 25px rgba(0,0,0,0.25);

            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
        }

        .site-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            font-weight: 600;
        }

        .site-logo img {
            width: 40px;
            height: auto;
            border-radius: 8px;
        }

        .nav a {
            margin-left: 25px;
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            opacity: 0.85;
            transition: 0.2s;
        }

        .nav-links a {
            margin-left: 25px;
            text-decoration: none;   /* ⛔ Removes underline */
            cursor: pointer;
            color: #fff;
            opacity: .8;
            transition: .2s;
        }

        .nav .nav-link.active,
        .nav .nav-link.active:hover {
        opacity: 1;
            text-decoration: none;   /* ⛔ Removes underline */
            font-weight: 600;
        }

        .nav .nav-link:hover {
            background: rgba(255,255,255,0.1);
            transform: translateX(8px) scale(1.02);
        }

        /* CONTENT WRAPPER */
        .form-wrapper {
            position: relative;
            z-index: 1; /* Ensures content sits above the blur */
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 75px 20px 0px; /* Space for fixed navbar */
        }

        /* FORM CARD */
        .report-card {
            width: 100%;
            max-width: 500px;
            background: rgba(255, 255, 255, 0.9); /* Higher opacity for readability */
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .report-card h2 {
            margin-bottom: 5px;
            font-size: 26px;
            color: #000;
            text-align: center;
        }

        .input-group {
            margin-bottom: 15px;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }

        .input-group select, 
        .input-group input, 
        .input-group textarea {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #ccc;
            background: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }

        .input-group textarea {
            resize: none;
            height: 90px;
        }

        .btn-container {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn-cancel {
            flex: 1;
            background: #e0e0e0;
            color: #444;
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-cancel:hover {
            background: #d4d4d4;
        }

        /* Ensure Navbar stays on top */
        .nav {
            z-index: 1000;
        }
    </style>
</head>
<body>

<header class="nav">
    <div class="site-logo">
        <img src="logocityhall.png" alt="LGU Logo">
        <span>LGU Citizen Portal</span>
    </div>
    <div class="nav-links">
        <a href="citizen.php">Home</a>
        <a href="services.php" class="active">Services</a>
        <a href="">Requests</a>
    </div>
</header>

<div class="form-wrapper">
    <div class="report-card">
        <h2>Maintenance Report</h2>
        
        <form action="#">
            <div class="input-group">
                <label>Infrastructure</label>
                <select required>
                    <option value="" disabled selected>Select Infrastructure...</option>
                    <option value="Roads">Roads</option>
                    <option value="Street Lights">Street Lights</option>
                    <option value="Drainage">Drainage</option>
                    <option value="Public Facilities">Public Facilities</option>
                </select>
            </div>

            <div class="input-group">
                <label>Location(or paste the link from Google maps)</label>
                <input type="text" placeholder="Street, Barangay, Landmark" required>
            </div>

            <div class="input-group">
                <label>Issue / Damage Description</label>
                <textarea placeholder="Describe the problem in detail..." required></textarea>
            </div>

            <div class="input-group">
                <label>Evidence (Upload Image of the issue in 3 different angles)</label>
                <input type="file" accept="image/*" required>
            </div>

            <div class="btn-container">
                <button type="button" class="btn-cancel" onclick="window.history.back()">Cancel</button>
                <button type="submit" class="btn-primary">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Set default date to today
    document.getElementById('submissionDate').valueAsDate = new Date();
</script>

</body>
</html>