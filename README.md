# Dental Clinic Management System

A comprehensive web-based management system for dental clinics built with PHP, MySQL, and Bootstrap 5.

## 🚀 Features

### Core Functionality

- **User Authentication & Authorization** - Secure login system with role-based access
- **Dashboard** - Real-time overview with financial summaries and alerts
- **Income Management** - Track consultation fees and product sales
- **Expense Management** - Monitor clinic expenses and costs
- **Inventory Management** - Manage dental supplies with stock tracking
- **Doctor Management** - Maintain doctor information and consultation fees
- **User Management** - Admin and staff user accounts

### Advanced Features

- **Product Sales Workflow** - Inventory-first approach with automatic stock updates
- **Real-time Stock Alerts** - Low stock notifications and threshold management
- **Month-wise Filtering** - Dashboard data filtering by month/year
- **Export Functionality** - CSV export for reports and analysis
- **Search & Filter** - Advanced search across all entities
- **Responsive Design** - Mobile-friendly interface with Bootstrap 5

## 🛠️ Technology Stack

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL 5.7+
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5.3.0
- **Icons**: Font Awesome 6.0.0
- **Charts**: Chart.js 3.9.1
- **Security**: CSRF protection, password hashing, input validation

## 📋 Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Modern web browser

## 🚀 Installation

### 1. Clone or Download

```bash
git clone <repository-url>
cd clinic
```

### 2. Database Setup

1. Create a MySQL database named `dental_clinic_db`
2. Import the database structure (tables will be created automatically on first run)

### 3. Configuration

1. Update database credentials in `config/database.php`:

   ```php
   private $host = 'localhost';
   private $dbname = 'dental_clinic_db';
   private $username = 'your_username';
   private $password = 'your_password';
   ```

2. Update application URL in `config/config.php`:
   ```php
   define('DCMT_APP_URL', 'http://your-domain.com/clinic');
   ```

### 4. Web Server Configuration

1. Point your web server to the `clinic` directory
2. Ensure PHP has write permissions for:
   - `logs/` directory
   - `uploads/` directory

### 5. Access the Application

1. Navigate to your clinic directory in a web browser
2. The system will automatically create tables and default data
3. Login with default credentials:
   - **Username**: `admin`
   - **Password**: `admin@123`

## 🔐 Default Login

- **Username**: `admin`
- **Password**: `admin@123`
- **Role**: Administrator

> **⚠️ Security Note**: Change the default password immediately after first login!

## 📁 Project Structure

```
clinic/
├── assets/                 # CSS, JS, and image files
├── auth/                  # Authentication system
│   ├── login.php         # Login page
│   ├── logout.php        # Logout handler
│   └── check_auth.php    # Authentication verification
├── config/               # Configuration files
│   ├── database.php      # Database connection and setup
│   └── config.php        # Application configuration
├── includes/             # Common includes
│   ├── header.php        # Page header with navigation
│   └── footer.php        # Page footer with scripts
├── pages/                # Main application pages
│   ├── dashboard/        # Dashboard with tabs
│   ├── income/           # Income management
│   ├── expenses/         # Expense management
│   ├── inventory/        # Inventory management
│   ├── settings/         # System settings
│   └── users/            # User management
├── uploads/              # File uploads directory
├── logs/                 # Application logs
├── index.php             # Main entry point
└── README.md             # This file
```

## 🔧 Configuration Options

### Database Settings

- Table prefix: `dcmt_` (configurable)
- Auto-creation of tables and indexes
- Default data insertion

### Security Settings

- Session lifetime: 1 hour (configurable)
- CSRF token lifetime: 30 minutes
- Password minimum length: 8 characters

### Application Settings

- Items per page: 20 (configurable)
- Date format: Y-m-d
- Currency: USD (configurable)

## 📊 Database Schema

### Core Tables

- `dcmt_users` - User accounts and roles
- `dcmt_doctor_services` - Doctor-role user service assignments
- `dcmt_inventory` - Product inventory with stock tracking
- `dcmt_income` - Income records (consultations and sales)
- `dcmt_income_breakdown` - Unified service/product line items
- `dcmt_expenses` - Expense records
- `dcmt_settings` - Application configuration

### Key Features

- **Foreign Key Relationships** - Data integrity and referential constraints
- **Audit Trail** - Track who created/modified records
- **Stock Management** - Real-time inventory updates
- **Performance Indexes** - Optimized database queries

## 🎯 Usage Guide

### 1. Dashboard Overview

- View monthly financial summaries
- Monitor low stock alerts
- Access recent transactions
- Filter data by month/year

### 2. Income Management

- **Consultations**: Select doctor, automatic fee calculation
- **Product Sales**: Choose from inventory, automatic stock updates
- **Payment Tracking**: Cash vs. online payment modes

### 3. Inventory Management

- Add new products with categories
- Set minimum stock thresholds
- Real-time stock level monitoring
- Automatic stock deduction on sales

### 4. Expense Tracking

- Categorize expenses
- Track expense dates and amounts
- Generate expense reports

## 🔒 Security Features

- **CSRF Protection** - All forms protected against cross-site request forgery
- **Input Validation** - Server-side validation and sanitization
- **Password Hashing** - Secure password storage using PHP's password_hash()
- **Session Management** - Secure session handling with configurable timeouts
- **SQL Injection Prevention** - PDO prepared statements throughout
- **XSS Protection** - Output escaping and sanitization

## 📱 Responsive Design

- **Mobile-First Approach** - Optimized for all device sizes
- **Bootstrap 5.3.0** - Modern, responsive grid system
- **Touch-Friendly Interface** - Optimized for mobile devices
- **Progressive Enhancement** - Works on all modern browsers

## 🚀 Development

### Adding New Features

1. Follow the existing naming conventions
2. Use the `dcmt_` prefix for all database elements
3. Implement proper validation and error handling
4. Add appropriate logging for audit trails

### Code Standards

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Include proper documentation and comments
- Implement proper error handling

## 🐛 Troubleshooting

### Common Issues

1. **Database Connection Failed**

   - Check database credentials in `config/database.php`
   - Ensure MySQL service is running
   - Verify database exists

2. **Tables Not Created**

   - Check PHP error logs
   - Ensure database user has CREATE privileges
   - Verify PHP PDO extension is enabled

3. **Permission Denied**

   - Check file permissions for `logs/` and `uploads/` directories
   - Ensure web server has write access

4. **Session Issues**
   - Check PHP session configuration
   - Verify session directory permissions
   - Check for conflicting session settings

### Debug Mode

Enable debug mode in `config/config.php`:

```php
define('DCMT_DEBUG_MODE', true);
```

## 📈 Performance Optimization

- Database indexes on frequently queried fields
- Pagination for large datasets
- Efficient SQL queries with proper JOINs
- Optimized CSS and JavaScript loading

## 🔄 Updates and Maintenance

### Regular Maintenance

- Monitor log files for errors
- Backup database regularly
- Update PHP and MySQL versions
- Review and clean old log files

### Backup Strategy

- Database: Daily automated backups
- Files: Weekly backups of uploads and configuration
- Logs: Monthly rotation and archiving

## 📞 Support

For technical support or feature requests:

- Check the documentation
- Review error logs
- Contact the development team

## 📄 License

This project is proprietary software. All rights reserved.

## 🎉 Acknowledgments

- Bootstrap team for the excellent CSS framework
- Font Awesome for the icon library
- Chart.js for data visualization
- PHP community for best practices and standards

---

**Version**: 1.0.0  
**Last Updated**: <?php echo date('F j, Y'); ?>  
**Compatibility**: PHP 7.4+, MySQL 5.7+
