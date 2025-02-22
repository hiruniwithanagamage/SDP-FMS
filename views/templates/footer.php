<!DOCTYPE html>
<html>
<head>
    <style>
        .footer {
            background-color:rgb(10, 32, 72);
            color: #f5f5f5;
            padding: 3rem 0 1rem 0;
            margin-top: auto;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section {
            margin-bottom: 1.5rem;
        }

        .footer-section h3 {
            color: #ffffff;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .footer-section p {
            color: #bebebe;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .footer-section ul {
            list-style: none;
            padding: 0;
        }

        .footer-section ul li {
            margin-bottom: 0.5rem;
        }

        .footer-section a {
            color: #bebebe;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-section a:hover {
            color: #ffffff;
        }

        .social-icons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-icons a {
            color: #bebebe;
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }

        .social-icons a:hover {
            color: #ffffff;
        }

        address {
            font-style: normal;
            color: #bebebe;
            line-height: 1.6;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            margin-top: 2rem;
            border-top: 1px solid #333;
        }

        .footer-bottom p {
            color: #bebebe;
            font-size: 0.9rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .footer-container {
                grid-template-columns: 1fr;
            }
            
            .footer-section {
                text-align: center;
            }
            
            .social-icons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <footer class="footer">
        <div class="footer-container">
            <!-- Company Info -->
            <div class="footer-section">
                <h3>FinanceMS</h3>
                <p>Secure and efficient financial management solutions for modern businesses.</p>
                <div class="social-icons">
                    <a href="#"><i class="fas fa-github"></i></a>
                    <a href="#"><i class="fas fa-envelope"></i></a>
                    <a href="#"><i class="fas fa-phone"></i></a>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="footer-section">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="security.php"><i class="fas fa-shield-alt"></i> Security</a></li>
                    <li><a href="support.php"><i class="fas fa-question-circle"></i> Support</a></li>
                </ul>
            </div>

            <!-- Legal -->
            <div class="footer-section">
                <h3>Legal</h3>
                <ul>
                    <li><a href="privacy.php">Privacy Policy</a></li>
                    <li><a href="terms.php">Terms of Service</a></li>
                    <li><a href="cookies.php">Cookie Policy</a></li>
                </ul>
            </div>

            <!-- Contact -->
            <div class="footer-section">
                <h3>Contact Us</h3>
                <address>
                    Pahalagama, Ekiriya<br>
                    Rikillagaskada<br>
                    <a href="hirunihansika625@gmail.com">hirunihansika625@gmail.com</a><br>
                    <a href="tel:+">+94 71 562 0806</a>
                </address>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="footer-bottom">
            <p>&copy; <?php echo date("Y"); ?> FinanceMS. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>