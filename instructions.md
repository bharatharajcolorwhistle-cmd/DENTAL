# Dental Clinic Management System - Development Instructions

## 🏥 Application Overview

A comprehensive web-based management system for dental clinics built with PHP, MySQL, and Bootstrap 5. The system provides complete management of income, expenses, inventory, doctors, and users with real-time stock tracking and financial reporting.

## 🛠️ Technology Stack

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL 5.7+
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5.3.0
- **Icons**: Font Awesome 6.0.0
- **Charts**: Chart.js 3.9.1
- **Security**: CSRF protection, password hashing, input validation
- **Naming Convention**: All functions, classes, and database elements use `dcmt_` prefix for uniqueness

## 📋 System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Modern web browser
- 50MB disk space minimum

## 🗄️ Database Schema

**Note**: All database tables, columns, functions, and classes use the `dcmt_` prefix to ensure uniqueness and avoid conflicts with other applications.

### Core Tables

1. **dcmt_users** - User accounts and roles

   - dcmt_id, dcmt_username, dcmt_email, dcmt_password
   - dcmt_full_name, dcmt_role (admin/staff), dcmt_status
   - dcmt_phone, dcmt_address, dcmt_notes
   - dcmt_last_login, dcmt_created_by, timestamps

2. **dcmt_doctor_services** - Doctor-role users assigned to services

   - dcmt_id, dcmt_user_id (doctor user), dcmt_service_id
   - dcmt_price, dcmt_status, dcmt_created_by, timestamps

3. **dcmt_inventory** - Product inventory with stock tracking

   - dcmt_id, dcmt_name, dcmt_sku, dcmt_description
   - dcmt_category_id, dcmt_quantity, dcmt_min_quantity
   - dcmt_price, dcmt_status, dcmt_supplier, timestamps

4. **dcmt_income** - Income records (consultations and sales)

   - dcmt_id, dcmt_patient_name, dcmt_type (consultation/product_sale)
   - dcmt_description, dcmt_amount, dcmt_consultation_fee
   - dcmt_payment_mode, dcmt_payment_status, dcmt_user_id
   - dcmt_transaction_date, timestamps

5. **dcmt_income_breakdown** - Unified service/product line items

   - dcmt_id, dcmt_income_id, dcmt_inventory_id
   - dcmt_quantity, dcmt_price, dcmt_total, dcmt_user_id

6. **dcmt_expenses** - Expense records

   - dcmt_id, dcmt_title, dcmt_description, dcmt_category_id
   - dcmt_amount, dcmt_payment_method, dcmt_payment_status
   - dcmt_expense_date, dcmt_notes, timestamps

7. **dcmt_expense_categories** - Expense categories

   - dcmt_id, dcmt_name, dcmt_description, dcmt_status, timestamps

8. **dcmt_inventory_categories** - Inventory categories

   - dcmt_id, dcmt_name, dcmt_description, dcmt_status, timestamps

9. **dcmt_settings** - Application configuration

   - dcmt_id, dcmt_setting_key, dcmt_setting_name, dcmt_setting_value
   - dcmt_setting_type, dcmt_category, dcmt_required, timestamps

10. **dcmt_activity_log** - System activity tracking
    - dcmt_id, dcmt_user, dcmt_activity, dcmt_details, dcmt_created_at

## 🚀 Core Features

### 1. User Authentication & Authorization

- Secure login system with role-based access (admin/staff)
- Session management with timeout
- CSRF protection on all forms
- Password hashing with PHP password_hash()

### 2. Dashboard

- Real-time financial summaries
- Monthly income/expense charts
- Low stock alerts
- Recent transactions overview
- Month-wise data filtering

### 3. Income Management

- **Consultations**: Doctor selection with automatic fee calculation
- **Product Sales**: Inventory-based sales with stock updates
- Payment tracking (cash, card, bank transfer, online)
- Payment status management (completed, pending, failed)

### 4. Expense Management

- Categorized expense tracking
- Payment method and status tracking
- Date-based filtering and reporting
- Category management

### 5. Inventory Management

- Product catalog with SKU tracking
- Real-time stock level monitoring
- Minimum stock threshold alerts
- Automatic stock deduction on sales
- Category-based organization

### 6. Doctor Management

- Doctor profiles with specialization
- Consultation fee management
- Contact information tracking
- Status management

### 7. User Management (Admin Only)

- User account creation and management
- Role assignment (admin/staff)
- Profile management
- Status control

### 8. Settings Management

- Application configuration
- Currency settings
- Logo management
- Language settings (English/Spanish)

## 📁 Project Structure

```
dental/
├── assets/
│   ├── css/main.css          # Main stylesheet
│   └── js/main.js            # Main JavaScript file
├── auth/
│   ├── login.php             # Login page
│   ├── logout.php            # Logout handler
│   └── check_auth.php        # Authentication verification
├── config/
│   ├── config.php            # Application configuration
│   ├── database.php          # Database connection and setup
│   └── migrate_*.php         # Database migration files
├── includes/
│   ├── header.php            # Page header with navigation
│   └── footer.php            # Page footer with scripts
├── pages/
│   ├── dashboard/            # Dashboard with financial overview
│   ├── income/               # Income management (add, edit, view, export)
│   ├── expenses/             # Expense management
│   ├── inventory/            # Inventory management
│   ├── inventory_categories/ # Inventory category management
│   ├── expense_categories/   # Expense category management
│   ├── doctors/              # Doctor management
│   ├── users/                # User management
│   └── settings/             # System settings
├── lang/
│   ├── en/                   # English translations
│   └── es/                   # Spanish translations
├── uploads/                  # File uploads directory
├── logs/                     # Application logs
├── index.php                 # Main entry point
└── instructions.md           # This file
```

## 🔧 Development Setup Instructions

### Naming Convention

All functions, classes, database tables, and configuration constants use the `dcmt_` prefix:

- **Functions**: `dcmt_redirect()`, `dcmt_get_current_user()`, `dcmt_validate_session()`
- **Classes**: `Dcmt_Database`
- **Database Tables**: `dcmt_users`, `dcmt_income`, `dcmt_inventory`
- **Constants**: `DCMT_APP_NAME`, `DCMT_APP_URL`, `DCMT_DEBUG_MODE`
- **Session Variables**: `$_SESSION['dcmt_user']`, `$_SESSION['dcmt_csrf_token']`

### Step 1: Environment Setup

1. Install XAMPP/WAMP/LAMP with PHP 7.4+ and MySQL 5.7+
2. Create project directory: `C:\xampp\htdocs\dental`
3. Ensure PHP extensions: PDO, PDO_MySQL, mbstring

### Step 2: Database Configuration

1. Update `config/database.php`:

```php
private $host = 'localhost';
private $dbname = 'dental_clinic_Management_db';
private $username = 'root';
private $password = '';
```

2. Update `config/config.php`:

```php
define('DCMT_APP_URL', 'http://localhost/dental');
define('DCMT_DB_HOST', 'localhost');
define('DCMT_DB_NAME', 'dental_clinic_Management_db');
define('DCMT_DB_USER', 'root');
define('DCMT_DB_PASS', '');
```

### Step 3: File Permissions

Set write permissions for:

- `logs/` directory (755)
- `uploads/` directory (755)

### Step 4: Database Initialization

The system automatically creates tables and default data on first run:

- Default admin user: admin/admin@123
- Default expense categories
- Default inventory categories
- Default settings

### Step 5: Access Application

1. Navigate to `http://localhost/dental`
2. Login with default credentials:
   - Username: `admin`
   - Password: `admin@123`

## 🎨 Frontend Development

### Naming Convention Standards

When developing or extending this application, always follow the established naming convention:

- **Functions**: Use `dcmt_` prefix (e.g., `dcmt_redirect()`, `dcmt_get_current_user()`)
- **Classes**: Use `Dcmt_` prefix (e.g., `Dcmt_Database`, `Dcmt_User`)
- **Database Tables**: Use `dcmt_` prefix (e.g., `dcmt_users`, `dcmt_income`)
- **Database Columns**: Use `dcmt_` prefix (e.g., `dcmt_id`, `dcmt_username`)
- **Constants**: Use `DCMT_` prefix (e.g., `DCMT_APP_NAME`, `DCMT_DEBUG_MODE`)
- **Session Variables**: Use `dcmt_` prefix (e.g., `$_SESSION['dcmt_user']`)
- **Configuration Keys**: Use `dcmt_` prefix (e.g., `dcmt_setting_key`)

This ensures no conflicts with other applications and maintains consistency throughout the codebase.

### CSS Architecture

- **Framework**: Bootstrap 5.3.0 for responsive grid and components
- **Custom Styles**: `assets/css/main.css` for application-specific styling
- **Typography**: Roboto font family (Google Fonts) with fallbacks
- **Color Scheme**:
  - Primary Blue: #60A1F8 (Custom brand color)
  - Primary Hover: #4A8FE8 (Darker shade for hover effects)
  - Success Green: #28a745
  - Danger Red: #dc3545
  - Warning Orange: #ffc107
  - Info Cyan: #17a2b8
  - Secondary Gray: #6c757d
- **Design System**: Clean, modern interface with subtle shadows and rounded corners
- **Project Prefix**: All CSS classes use `dcmt-` prefix for uniqueness

### JavaScript Features

- Bootstrap 5 components and modals
- Chart.js for data visualization
- Custom confirmation dialogs
- Form validation and AJAX operations
- Dynamic form field toggling

### UI/UX Design Specifications

#### Typography

- **Primary Font**: Roboto (Google Fonts)
  - Weights: 100, 300, 400, 500, 700, 900
  - Styles: Regular and Italic
  - Fallbacks: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif
- **Font Sizes**:
  - Headers: 1.5rem - 2.5rem (24px - 40px)
  - Body text: 1rem (16px)
  - Small text: 0.875rem (14px)
  - Form labels: 1rem (16px) with 500 weight
  - Card titles: 1.3125rem (21px) with bold weight
  - Table headers: 1.0625rem (17px) with 500 weight
  - Table data: 0.9375rem (15px)
  - Button text: 1rem (16px) with 500 weight
  - Filter button text: 1rem (16px) with 500 weight
  - Submit button text: 1rem (16px) with 600 weight

#### Color Palette

- **Primary**: #60A1F8 (Custom brand blue)
- **Primary Hover**: #4A8FE8 (Darker blue for hover effects)
- **Success**: #28a745 (Green)
- **Danger**: #dc3545 (Red)
- **Warning**: #ffc107 (Yellow)
- **Info**: #17a2b8 (Cyan)
- **Secondary**: #6c757d (Gray)
- **Light**: #f8f9fa (Light gray background)
- **Dark**: #343a40 (Dark text)
- **Form Labels**: #2979C9 (Blue for form labels)
- **Card Titles**: #666666 (Gray for card titles)

#### Component Styling

- **Cards**: White background, no border, rounded corners (12px), clean design
- **Filter Form Cards**: `dcmt-filter-form` class with consistent styling
- **Records Table Cards**: `dcmt-records-table` class with consistent styling
- **Buttons**:
  - Primary: `dcmt-btn-primary` class with #60A1F8 color, 15px 30px padding
  - Submit: `dcmt-btn-submit` class with #60A1F8 color, 12px 24px padding
  - Filter: `dcmt-filter-btn` class with consistent padding and styling
- **Forms**: Clean inputs with focus states, 70px height for form controls
- **Navigation**: White background with shadow, dropdown menus with rounded corners
- **Alerts**: Color-coded with icons and dismissible functionality
- **Action Icons**: SVG icons with `btn-group-action` class for consistent styling

#### Design System & CSS Classes

The application uses a comprehensive design system with consistent CSS classes:

**Card Components:**

- `.dcmt-filter-form`: Search and filter form cards with consistent styling
- `.dcmt-records-table`: Data table cards with consistent styling
- Both use 12px border radius, white background, and proper padding

**Button Components:**

- `.dcmt-btn-primary`: Primary action buttons (#60A1F8 color, 15px 30px padding)
- `.dcmt-btn-submit`: Form submit buttons (#60A1F8 color, 12px 24px padding)
- `.dcmt-filter-btn`: Filter form buttons (15px 30px padding, consistent styling)
- All buttons use Roboto font, 8px border radius, and smooth transitions

**Form Components:**

- Form controls: 70px height, 12px border radius, #B4B4B4 border
- Form labels: #2979C9 color, 16px (1rem) size, 500 weight
- Focus states: #667eea border with subtle shadow
- Input text: 16px (1rem), 400 weight
- Placeholder text: 16px (1rem), 400 weight, #6c757d color

**Typography:**

- Card titles: 21px (1.3125rem), bold weight, #666666 color
- Table headers: 17px (1.0625rem), 500 weight, #2979C9 color
- Table data: 15px (0.9375rem), #3C4556 color
- Form labels: 16px (1rem), 500 weight, #2979C9 color
- Button text: 16px (1rem), 500-600 weight
- Body text: 16px (1rem), 400 weight
- Small text: 14px (0.875rem), 400 weight

**Font Size Reference:**

- 14px = 0.875rem (Small text, captions)
- 15px = 0.9375rem (Table data)
- 16px = 1rem (Body text, buttons, form labels, inputs)
- 17px = 1.0625rem (Table headers)
- 21px = 1.3125rem (Card titles)
- 24px = 1.5rem (Small headers)
- 32px = 2rem (Medium headers)
- 40px = 2.5rem (Large headers)

**Action Icons:**

- SVG icons: `edit.svg`, `delete.svg`, `view-filled.svg`, `lock.svg`
- Button group: `btn-group-action` class for consistent spacing
- Hover effects: Subtle lift and shadow effects

**Uniform Design Implementation:**
All index pages follow a consistent design pattern:

- No title sections or summary cards (clean, minimal design)
- Search and filter forms wrapped in `dcmt-filter-form` cards
- Data tables wrapped in `dcmt-records-table` cards
- Consistent action icons using SVG files
- Uniform button styling with project prefix classes
- Consistent typography and spacing throughout

**Page Structure:**

1. Add buttons (where applicable) - right-aligned with `dcmt-btn-primary` class
2. Search and filter form - `dcmt-filter-form` card with proper header
3. Data table - `dcmt-records-table` card with proper header
4. Consistent action buttons in table rows

#### Layout & Spacing

- **Container**: Bootstrap container-fluid with proper padding
- **Grid**: 12-column responsive grid system
- **Spacing**: Consistent margin/padding using Bootstrap spacing utilities
- **Breakpoints**:
  - xs: <576px (mobile)
  - sm: ≥576px (tablet)
  - md: ≥768px (desktop)
  - lg: ≥992px (large desktop)
  - xl: ≥1200px (extra large)

### Responsive Design

- **Mobile-First Approach**: Designed for mobile devices first, then enhanced for larger screens
- **Bootstrap Grid System**: Responsive 12-column layout
- **Touch-Friendly Interface**: Large buttons and touch targets (minimum 44px)
- **Progressive Enhancement**: Works on all devices, enhanced on modern browsers
- **Flexible Images**: Responsive images that scale with container
- **Collapsible Navigation**: Mobile menu that collapses on smaller screens

#### UI Components & Patterns

##### Navigation

- **Top Navigation Bar**: White background with subtle shadow
- **Brand Logo**: Left-aligned with icon and site name
- **Dropdown Menus**: Rounded corners, hover effects, icon + text labels
- **User Profile**: Avatar with initials, dropdown for profile actions
- **Responsive Menu**: Collapses to hamburger menu on mobile

##### Dashboard Cards

- **Financial Summary Cards**: Color-coded with icons and large numbers
- **Chart Containers**: Clean white background with subtle borders
- **Alert Cards**: Color-coded (success, warning, danger) with dismissible functionality
- **Recent Activity**: List format with timestamps and action descriptions

##### Forms

- **Input Fields**: Clean design with focus states and validation styling
- **Select Dropdowns**: Custom styled with search functionality
- **Date Pickers**: Bootstrap date input with proper formatting
- **File Uploads**: Drag-and-drop styling with progress indicators
- **Form Validation**: Real-time validation with error messages below fields

##### Tables & Lists

- **Data Tables**: Striped rows, hover effects, responsive design
- **Action Buttons**: Icon + text, color-coded by action type
- **Pagination**: Bootstrap pagination with page numbers
- **Search/Filter**: Inline search with clear functionality
- **Export Buttons**: CSV export with loading states

##### Modals & Dialogs

- **Confirmation Dialogs**: Custom styled with warning icons
- **Form Modals**: Full-screen on mobile, centered on desktop
- **Loading States**: Spinner animations and progress bars
- **Success/Error Messages**: Toast notifications with auto-dismiss

##### Icons & Visual Elements

- **Font Awesome 6.0.0**: Comprehensive icon library
- **Color Coding**: Consistent color usage across all components
- **Loading Animations**: Smooth transitions and hover effects
- **Status Indicators**: Color-coded badges and indicators

## 🔒 Security Implementation

### Authentication

- Session-based authentication
- Password hashing with `password_hash()`
- Session timeout (1 hour)
- CSRF token protection

### Input Validation

- Server-side validation for all inputs
- SQL injection prevention with PDO prepared statements
- XSS protection with `htmlspecialchars()`
- File upload validation

### Database Security

- Foreign key constraints
- Prepared statements throughout
- Input sanitization
- Error logging without sensitive data exposure

## 🌐 Internationalization

### Language Support

- English (en) and Spanish (es)
- Translation files in `lang/` directory
- Dynamic language switching
- Fallback to Spanish if translation missing

### Translation Structure

```php
// Usage in code
echo trans('module', 'key', 'default_value');

// Translation file structure
return [
    'key' => 'Translated Text',
    'another_key' => 'Another Translation'
];
```

## 📊 Key Business Logic

### Income Processing

1. **Consultation Flow**:

   - Select doctor → Auto-fill consultation fee
   - Enter patient details → Calculate total
   - Process payment → Record transaction

2. **Product Sale Flow**:
   - Select products from inventory
   - Check stock availability
   - Calculate totals → Update stock levels
   - Process payment → Record transaction

### Stock Management

- Real-time stock tracking
- Automatic deduction on sales
- Low stock alerts (configurable threshold)
- Stock restoration on transaction cancellation

### Financial Reporting

- Monthly income/expense summaries
- Payment status tracking
- Export functionality (CSV)
- Chart visualization with Chart.js

## 🚀 Deployment Checklist

### Pre-deployment

- [ ] Update database credentials
- [ ] Set `DCMT_DEBUG_MODE` to false
- [ ] Configure proper file permissions
- [ ] Test all functionality
- [ ] Backup database

### Production Configuration

- [ ] Use HTTPS
- [ ] Set secure session cookies
- [ ] Configure proper error logging
- [ ] Set up database backups
- [ ] Monitor application logs

## 🔄 Maintenance

### Regular Tasks

- Monitor log files for errors
- Backup database regularly
- Update PHP and MySQL versions
- Review and clean old log files
- Check disk space usage

### Performance Optimization

- Database indexes on frequently queried fields
- Pagination for large datasets
- Efficient SQL queries with proper JOINs
- Optimized CSS and JavaScript loading

## 🐛 Troubleshooting

### Common Issues

1. **Database Connection Failed**: Check credentials in `config/database.php`
2. **Tables Not Created**: Check PHP error logs and database permissions
3. **Permission Denied**: Check file permissions for `logs/` and `uploads/`
4. **Session Issues**: Check PHP session configuration

### Debug Mode

Enable in `config/config.php`:

```php
define('DCMT_DEBUG_MODE', true);
```

## 📈 Future Enhancements

### Potential Features

- Patient management system
- Appointment scheduling
- Treatment history tracking
- Advanced reporting and analytics
- Multi-clinic support
- API integration
- Mobile app development

### Technical Improvements

- Implement caching system
- Add unit testing
- Implement API endpoints
- Add real-time notifications
- Improve mobile responsiveness

## 📞 Support Information

### Default Login Credentials

- **Username**: admin
- **Password**: admin@123
- **Role**: Administrator

### Important Notes

- Change default password immediately after first login
- Regular database backups are essential
- Monitor application logs for errors
- Keep PHP and MySQL versions updated

---

**Version**: 1.1.0  
**Last Updated**: December 2024  
**Compatibility**: PHP 7.4+, MySQL 5.7+  
**License**: Proprietary Software

## 🎨 Design Updates (v1.1.0)

### New Design Features

- **Custom Primary Color**: #60A1F8 (replacing #667eea)
- **Typography**: Updated to Roboto font family
- **Uniform Design**: All index pages follow consistent design pattern
- **CSS Classes**: Project-prefixed classes (`dcmt-`) for all components
- **Button System**: Standardized button classes with consistent styling
- **Card Components**: Generic filter and table card classes
- **Action Icons**: SVG-based action buttons with consistent styling

### Breaking Changes

- Updated primary color from #667eea to #60A1F8
- Changed font family from Lato to Roboto
- Renamed CSS classes to use `dcmt-` prefix
- Updated form submit button class from `dcmt-btn-add-income` to `dcmt-btn-submit`
