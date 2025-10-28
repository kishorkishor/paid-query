# Customer Portal Features - Comprehensive Report

## Overview
The Cosmic Trading customer portal is a comprehensive web application built with PHP backend and modern JavaScript frontend, featuring Clerk.js authentication. It provides end-to-end functionality from query submission to order fulfillment and delivery management.

## 1. Authentication & Security

### Clerk.js Integration
- **JWT Token Authentication**: Secure user authentication using Clerk.js
- **Token Verification**: Server-side validation of authentication tokens
- **User Session Management**: Persistent login sessions with automatic token refresh
- **User Isolation**: Customers can only access their own data and queries

### Security Features
- **CSRF Protection**: Form submissions include proper token validation
- **File Upload Security**: Validated file types and secure storage paths
- **SQL Injection Prevention**: Prepared statements for all database queries
- **XSS Protection**: HTML escaping for all user inputs

## 2. Main Dashboard (`index.html`)

### Dashboard Statistics
- **Query Counts Display**:
  - Total queries
  - New queries
  - Assigned queries
  - In Process queries
  - Red Flag queries
- **Real-time Updates**: Statistics refresh automatically
- **Visual Indicators**: Color-coded status badges

### Navigation System
- **Primary Navigation**: Dashboard, My Queries, New Query
- **Quick Actions**: Direct access to order management
- **Breadcrumb Navigation**: Clear navigation hierarchy

### Recent Activity
- **Recent Queries Table**: Shows latest queries with key information
- **Status Tracking**: Visual status indicators
- **Quick Actions**: Direct links to view query details

## 3. Query Management System

### Query Creation (`New Query` Section)

#### Basic Information (Always Visible)
- **Customer Details**:
  - Customer name (required)
  - Phone number (required, with placeholder format)
- **Service Description**:
  - Detailed service requirements (required)
  - Multi-line text area for comprehensive descriptions
- **Country Selection**:
  - Dynamic dropdown populated via API
  - Required field with validation

#### Extended Information (Expandable)
- **Product Information**:
  - Product name (optional)
  - Product links (comma-separated URLs)
  - Quantity specifications
- **Query Classification**:
  - Query type: Other, Sourcing, Shipping, Both
  - Shipping mode: Unknown, Air, Sea
- **Financial Details**:
  - Budget in USD (with decimal precision)
  - Currency handling
- **Physical Specifications**:
  - Label type (FBA, Custom, Plain)
  - Carton count
  - CBM (Cubic Meters) with 4 decimal precision
- **Additional Details**:
  - Delivery address
  - Special notes and requirements
- **File Attachments**:
  - Multiple file upload support
  - Accepted formats: PDF, JPG, JPEG, PNG, WEBP, HEIC
  - File size validation

#### Form Features
- **Progressive Disclosure**: Basic fields shown first, extended fields on demand
- **Dynamic Validation**: Real-time form validation
- **Country Loading**: AJAX-powered country list population
- **Form Reset**: Ability to reset form to basic state
- **Submission Feedback**: Success/error messaging

### Query Details (`customer/query.php`)

#### Query Information Display
- **Query Metadata**:
  - Query code and ID
  - Current status with visual indicators
  - Assigned team information
  - Priority level
  - Query type classification
  - Creation timestamp

#### Product Information
- **Product Details**: Formatted display of service requirements
- **Product Links**: Clickable links with external link indicators
- **Attachments Gallery**:
  - Image previews for image files
  - Download links for all file types
  - File type and size information

#### Communication System
- **Message Thread**: Chronological message display
- **Message Types**:
  - Customer messages (inbound)
  - Team messages (outbound)
  - System notifications
- **Message Formatting**:
  - Timestamp display
  - Sender identification
  - Message content with proper formatting
- **New Message Form**:
  - Multi-line text input
  - Real-time character counting
  - Message submission with validation

#### Price Management
- **Submitted Price Display**:
  - Product price (for sourcing queries)
  - Shipping price (for shipping queries)
  - Combined pricing (for both types)
  - Currency display
- **Price Approval Banner**: Prominent display when prices are approved

#### Action Buttons (Price Approved State)
- **Approve Order**: Convert query to order
- **Negotiate Price**: Price negotiation modal
- **Close Query**: End query process

#### Price Negotiation Modal
- **Dynamic Fields**: Based on query type
  - Product price negotiation (sourcing/both)
  - Shipping price negotiation (shipping/both)
- **Additional Notes**: Context and requirements
- **Form Validation**: Required field validation
- **Modal Controls**: Open/close with keyboard support

## 4. Order Management System

### Order List (`customer/orders.php`)

#### Order Overview
- **Order Information**:
  - Order code and ID
  - Total amount with currency formatting
  - Current status with visual indicators
  - Payment status tracking
  - Creation date
- **Access Control**: Token-based secure access
- **Empty State**: User-friendly message when no orders exist

### Order Details (`customer/order_details.php`)

#### Order Summary
- **Order Metadata**:
  - Order code and creation date
  - Source query information
  - Current status and payment status
  - Approved pricing information
- **Financial Summary**:
  - Total amount to pay
  - Amount paid so far
  - Remaining balance
  - Payment progress tracking

#### Payment Options
- **Bank Payment**:
  - Multiple bank account options
  - Proof upload requirement
  - Transaction tracking
- **Wallet Payment**:
  - Real-time wallet balance display
  - Custom amount input
  - Instant payment processing
  - Balance validation

#### Item Management
- **Item Details Table**:
  - Product information
  - Links and specifications
  - Detailed descriptions
- **Empty State Handling**: Graceful display when no items

#### Carton Management System
- **Carton Overview**:
  - Individual carton tracking
  - Weight and volume information
  - Status indicators (Paid, Partially Paid, At BD, In Transit)
- **Payment Selection**:
  - Checkbox selection for unpaid cartons
  - Real-time total calculation
  - Bulk payment options
- **Delivery Management**:
  - Delivery request for paid cartons
  - OTP code generation and display
  - Delivery status tracking
- **OTP System**:
  - One-time password generation
  - Secure code display
  - Delivery verification

#### Bangladesh Charges
- **BD Charges Display**:
  - Total outstanding charges
  - Per-carton breakdown
  - Payment options
- **Payment Processing**:
  - Bank payment option
  - Wallet payment option
  - Bulk payment support

## 5. Payment System

### Bank Payment (`customer/payment.php`)

#### Bank Account Management
- **Account Display**:
  - Bank name and branch information
  - Account name and number
  - Copy-to-clipboard functionality
  - Visual bank identification
- **Account Selection**: Quick selection from order details

#### Payment Submission
- **Multi-line Payment Support**:
  - Multiple payment lines
  - Different bank accounts per line
  - Individual amount specification
- **Amount Validation**:
  - Per-line amount limits
  - Total amount validation
  - Real-time calculation
- **Proof Upload**:
  - Required proof for each payment line
  - Multiple file format support
  - Secure file storage
- **Transaction Tracking**:
  - Unique transaction codes
  - Payment history display
  - Status tracking (verifying, verified, rejected)

#### User Interface Features
- **Dynamic Form**:
  - Add/remove payment lines
  - Real-time validation
  - Amount capping with user feedback
- **Visual Feedback**:
  - Toast notifications
  - Progress indicators
  - Error highlighting

### Wallet System (`customer/wallet.php`)

#### Wallet Overview
- **Balance Display**:
  - Current wallet balance
  - Currency information
  - Real-time updates
- **Wallet Context**:
  - Linked to specific orders
  - Customer identification
  - Transaction history

#### Top-up Functionality
- **Top-up Submission**:
  - Multiple bank account options
  - Amount specification
  - Proof upload requirement
- **Transaction Processing**:
  - Verification workflow
  - Status tracking
  - Balance updates

#### Transaction History
- **Ledger Display**:
  - Complete transaction history
  - Entry type classification
  - Amount and currency
  - Associated orders/cartons
  - Timestamps
- **Transaction Types**:
  - Top-up verified
  - Manual credit
  - Charge shipping captured
  - Charge sourcing captured
  - Adjustments and refunds

### Shipment Payment (`customer/shipment_payment.php`)

#### BD Charges Payment
- **Charge Calculation**:
  - Per-carton charge calculation
  - Total outstanding amount
  - Selected carton filtering
- **Payment Processing**:
  - Bank payment option
  - Wallet payment integration
  - Amount validation
- **Carton Selection**:
  - Individual carton selection
  - Bulk selection options
  - Real-time total calculation

## 6. User Interface & Experience

### Design System
- **Color Scheme**:
  - Primary: Dark blue (#111827)
  - Background: Light gray (#f7f7fb)
  - Success: Green (#10b981)
  - Warning: Orange (#f59e0b)
  - Error: Red (#ef4444)
- **Typography**: System UI fonts with proper hierarchy
- **Spacing**: Consistent padding and margins
- **Border Radius**: Rounded corners for modern look

### Responsive Design
- **Mobile Optimization**: Responsive grid layouts
- **Touch-friendly**: Large touch targets
- **Adaptive Layouts**: Flexible grid systems
- **Viewport Optimization**: Proper viewport meta tags

### Interactive Elements
- **Buttons**: Multiple button styles and states
- **Forms**: Real-time validation and feedback
- **Modals**: Overlay dialogs with proper focus management
- **Tables**: Sortable and filterable data tables
- **Cards**: Information grouping with visual hierarchy

### User Feedback
- **Toast Notifications**: Non-intrusive success/error messages
- **Loading States**: Visual feedback during operations
- **Progress Indicators**: Step-by-step process visualization
- **Error Handling**: Clear error messages and recovery options

## 7. Technical Features

### Frontend Technologies
- **Vanilla JavaScript**: No framework dependencies
- **Modern ES6+**: Arrow functions, async/await, template literals
- **CSS Grid & Flexbox**: Modern layout techniques
- **Fetch API**: Modern HTTP requests
- **FormData API**: File upload handling

### Backend Integration
- **RESTful APIs**: Clean API endpoints
- **Database Integration**: MySQL with PDO
- **File Upload Handling**: Secure file processing
- **Session Management**: Server-side session handling
- **Error Logging**: Comprehensive error tracking

### Performance Optimizations
- **Lazy Loading**: On-demand content loading
- **Caching**: Static asset caching
- **Minification**: Optimized CSS and JavaScript
- **Image Optimization**: Proper image handling
- **Database Optimization**: Efficient queries

## 8. Integration Points

### External Services
- **Clerk.js**: Authentication and user management
- **Bank Integration**: Multiple bank account support
- **File Storage**: Secure file upload and storage
- **Email Notifications**: System notifications

### Internal Systems
- **Team Management**: Query assignment and communication
- **Accounts Integration**: Payment verification workflow
- **Delivery Management**: OTP-based delivery system
- **Inventory Tracking**: Carton and item management

## 9. Future Enhancement Opportunities

### UI/UX Improvements
- **Modern Framework**: React/Vue.js integration
- **Component Library**: Reusable UI components
- **Animation System**: Smooth transitions and micro-interactions
- **Dark Mode**: Theme switching capability
- **Accessibility**: WCAG compliance improvements

### Feature Enhancements
- **Real-time Updates**: WebSocket integration
- **Mobile App**: Native mobile application
- **Advanced Analytics**: Customer dashboard analytics
- **Multi-language Support**: Internationalization
- **Advanced Search**: Query and order search functionality

### Technical Improvements
- **API Versioning**: Backward compatibility
- **Microservices**: Service-oriented architecture
- **Caching Layer**: Redis integration
- **CDN Integration**: Global content delivery
- **Monitoring**: Application performance monitoring

## Conclusion

The current customer portal provides a comprehensive solution for query management, order processing, and payment handling. While functional and feature-rich, there are significant opportunities for UI/UX improvements to enhance user experience, modernize the interface, and improve overall usability. The system's robust backend architecture and security features provide a solid foundation for future enhancements.
