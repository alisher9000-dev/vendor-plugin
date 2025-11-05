# 🧾 Vendor Registry Plugin

**Vendor Registry** is a powerful WordPress plugin that allows site owners to **import, manage, and display vendor profiles**.  
It includes a full admin interface for CSV imports and a responsive, AJAX-powered frontend search system for users to browse vendors by skills, rates, plans, and more.

---

## 🚀 Features

### 🛠️ Admin (Backend)
- **CSV Import System**
  - Upload and import vendors from CSV files.
  - Displays real-time progress and import logs.
  - Supports cancelling stuck or active imports safely.

- **Data Management**
  - Automatic table creation on activation.
  - Versioned database schema (`cb_vendor_registry_schema_version`).
  - Cache control (clear and flush vendor data).

- **Security**
  - Nonce-protected AJAX endpoints.
  - Vendor data is cached using WordPress transients with unique prefixes.

---

### 🌐 Frontend (Public)
- **AJAX Vendor Search**
  - Search vendors dynamically without reloading the page.
  - Filters by:
    - Skills (multi-select)
    - Hourly rate range
    - Plan type
    - Keyword search
    - Sorting (rate, rating, name, etc.)

- **Pagination**
  - Seamless navigation between pages of results using AJAX.

- **Filter Chips**
  - Active filters are shown as removable chips.
  - Reset and clear filters with one click.

- **Responsive Design**
  - Fully responsive vendor grid.
  - Works with or without the Select2 library.

---

## ⚙️ How It Works

### 🔹 Activation
When the plugin is activated, the **`Activator`** class:
- Creates necessary database tables via `DbSchema`.
- Saves schema version in WordPress options.
- Flushes rewrite rules to register REST routes.

### 🔹 Caching
The **`Cache`** class:
- Stores vendor data and transient results with a custom prefix.
- Supports get/set/delete operations.
- Flushes all vendor-related cached entries on demand.

### 🔹 Admin Import Page
The **admin page** (using `admin.js`) lets you:
- Upload CSV files for bulk vendor import.
- Monitor progress and recent imports.
- Cancel or retry imports if needed.
- Manage API codes for REST integrations.

### 🔹 Frontend Search
The **frontend script (`frontend.js`)**:
- Handles all filtering, searching, sorting, and pagination using AJAX.
- Updates the browser URL to reflect current filters.
- Displays results dynamically in a vendor grid layout.

---

## 🧰 Shortcodes / Usage

You can display the **Vendor Search Interface** on any page using the shortcode:

```php
[vendor_registry]
```

This will render:
- Search filters (skills, rate, plan, etc.)
- Dynamic vendor results grid
- Pagination controls

---

## 🔐 Security & Performance

- Nonce protection on all AJAX requests.
- Caching with unique transient prefixes for each vendor registry.
- Selective cache flushing, avoiding performance overhead.
- Graceful fallback if JS dependencies (like Select2) are not loaded.

---

## 🧑‍💻 Technical Overview

| Component | Description |
|------------|-------------|
| `Activator.php` | Handles plugin activation tasks — DB schema creation, option updates, rewrite flushing. |
| `Cache.php` | Provides transient-based caching with prefixing and bulk flush. |
| `admin.js` | Manages CSV imports, progress tracking, and status updates in the admin dashboard. |
| `frontend.js` | Handles vendor search UI, filtering, pagination, and AJAX rendering on the frontend. |

---

## 🪄 Requirements

- WordPress 5.8 or later  
- PHP 7.4 or later  
- MySQL 5.7+  
- jQuery (bundled with WordPress)  
- Optional: [Select2](https://select2.org/) library for enhanced dropdowns

---

## 🔧 Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Vendor Registry** from the WordPress “Plugins” menu.
3. Go to **Vendor Registry → Import Vendors** to import your vendor data.
4. Add `[vendor_registry]` shortcode to any page where you want the vendor search to appear.

---

## 🧹 Maintenance

- Use the admin “Clear Cache” or “Flush Data” options after large imports.
- Check schema version after plugin updates.
- Regularly monitor CSV import logs to ensure vendor data is updated properly.

---

## 📄 License

This plugin is licensed under the **GPLv2 or later**.  
You are free to modify and redistribute it under the same license.

---

## 💬 Author

**Vendor Registry** plugin by *CB Vendor Solutions (vendor123)*  
📧 Support: support@cbvendorsolutions.com  
🌐 Website: [https://cbvendorsolutions.com](https://cbvendorsolutions.com)

---

**In summary:**  
> Vendor Registry provides a complete vendor management system for WordPress —  
> from CSV imports to live AJAX-based vendor browsing — optimized for performance and scalability.
