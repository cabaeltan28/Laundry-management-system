# LaundryFlow v3 — Laundry Order Monitoring & Business Transparency System

A complete, ready-to-use system for **one laundry shop**. It's easy for counter staff, transparent for the owner, and convenient for customers.
Built with plain PHP 8 + MySQL + CSS and runs on XAMPP. You don't need a framework, Composer, Node or JavaScript.

Development team: Ian Ashtune Navarro · Rhealyn Cabarloc · Philip Mendoza II · Rodel Tan

---

## 1. Install (Windows + XAMPP), about 5 minutes

1. Copy the `laundryflow` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open **http://localhost/phpmyadmin**.
   - ⚠️ First click the **house icon** (top left) so that **no database is selected**. This protects your other databases (BALIK, etc.).
   - If an old `laundryflow` database exists and you don't need its data: click it, open **Operations**, and click **Drop the database**.
   - Click **Import**, choose `laundryflow/database/laundryflow.sql`, and click **Go**.
4. Open **http://localhost/laundryflow** and log in as `owner` / `owner123`.
5. For practice or presentations, go to **Settings → 🧪 Load demo data**. You can delete it **anytime** with **Delete all demo data**, and real data is never touched.

> Already using **v2** with real data? Import `database/upgrade_v2_to_v3.sql` instead (house icon first!).

| Role | Username | Password | PIN | Note |
|---|---|---|---|---|
| Owner | `owner` | `owner123` | — | keep, change the password |
| Employee | `jessa` | `staff123` | 1234 | demo account (Tagalog) |
| Employee | `carlo` | `staff123` | 5678 | demo account (Tagalog) |
| Customer | `angela` | `customer123` | — | demo account |

The demo accounts are removed by **Delete all demo data**. If they were used for real work, they are deactivated instead.

### Let customers scan the QR code on the claim stub
Phones cannot open `localhost`. In `config.php`, set `PUBLIC_URL` to the address phones can reach:
- same Wi-Fi as the shop computer: `http://192.168.1.10/laundryflow` (find the PC's IP with `ipconfig`)
- online hosting: `https://your-domain.com`

---

## 2. Why it is easy for staff

- **Tagalog or English**: one button at the bottom of the menu, saved per person. New employees start in Tagalog (you can change this in Settings).
- **Big picture buttons** on the Counter: ➕ New order · ✅ Release laundry · 📋 All orders · 💵 Cash closing.
- **One-screen new order**: type the mobile number and an old customer is found automatically. Tap the service, type the kilo, choose the payment, then press **SAVE ORDER**.
- **✨ Type the whole order in one line**, for example `Maria 0917 123 4567, 8 kilo wash dry fold, rush, bayad na gcash`, and the form fills itself.
- **Release in one step**: type the 4-digit pickup code in the big box, press Find, then **Received ₱… — Release laundry**. The payment is recorded and the laundry is released at the same time.
- **Work queue** with a pink "→ next step" button per order (Washing → Drying → Folding → Ready). The customer is notified automatically.
- **🔁 Repeat order** for regular customers.
- **Pickup codes never repeat** among open orders, so the counter never mixes up two customers.
- **Help page** with simple step-by-step instructions.

### Shortcut keys (Chrome/Edge: Alt + letter · Firefox: Alt + Shift + letter)

| Key | Action | Key | Action |
|---|---|---|---|
| Alt+H | Counter / Dashboard | Alt+K | Cash closing |
| Alt+N | New order | Alt+W | Switch user (PIN) |
| Alt+Z | Save order | Alt+I | Help |
| Alt+Q | Type pickup code | Alt+A | AI assistant (owner) |
| Alt+S | Search | Alt+R | Reports (owner) |
| Alt+P | Ready for pickup | Alt+X | Discrepancies (owner) |
| Alt+O | Orders | Alt+Y | Expenses (owner) |
| Alt+C | Customers | Ctrl+P | Print claim stub |

The letters are also shown beside each menu item.

---

## 3. AI features (Claude). They work even without AI.

| Feature | Where | Without an AI key |
|---|---|---|
| **Order reader**: understands orders typed in English, Tagalog or Taglish | New order | The built-in offline reader still finds the name, mobile number, kilo or pieces, bags, service, rush, payment, reference and care notes |
| **Business assistant**: e.g. "Magkano kita this week?" or "Who has the most discrepancies?" | Owner > AI assistant | Built-in answers for sales, profit, best services, busy hours, unpaid, unclaimed, staff and discrepancies |
| **Daily insights**: 4 practical tips every day | Owner dashboard | Rule-based tips (sales trend, unclaimed, unpaid, on-time rate, busy hours, cash shortages) |
| **Customer help**: e.g. "Kailan ready ang laundry ko?" | Customer > Ask us | Built-in answers about the customer's orders, prices, hours, pickup codes and payment |

### Turning on AI
1. Create an API key at **console.anthropic.com** (it's paid per use, and the default model is low-cost).
2. In `config.php`, set `AI_ENABLED` to `true` and paste your key into `AI_API_KEY`.
3. **XAMPP SSL error?** Download `https://curl.se/ca/cacert.pem`, save it (for example to `C:\xampp\php\extras\ssl\cacert.pem`), and put that path in `AI_CA_BUNDLE`.

If the AI fails (no internet, wrong key), the system automatically uses offline mode and retries after 5 minutes, so the counter never stops working.
**Privacy:** the business assistant sends only summary numbers. The customer helper sends only that customer's own orders.

---

## 4. Getting counter orders into the customer's app (no typing)

| Way | Who | How |
|---|---|---|
| **📱 Scan the QR code** (easiest) | Customer | Scan the code on the claim stub, then tap **Save to my account**. With no account yet, they tap **Create account** and the order is added automatically. |
| **Same mobile number** | Automatic | New counter orders with the customer's registered number appear in their app right away. |
| **Old orders + pickup code** | Customer | The app says "We found N orders with your number". The customer types any 4-digit pickup code from a stub or SMS to add them all. |
| **At the counter** | Staff | Customers → Edit → **Connect to app account** (username or mobile). |
| Order number + tracking code | Customer | A backup way, under My account. |

Security: other people's orders can't be added. Each way needs the stub, the customer's own number plus a pickup code, or the staff, and failed tries are limited.

## 5. Features that compete with commercial laundry systems

**Counter and customers**
- One-screen orders, automatic pricing (minimum kg, rush surcharge) and **realistic ready-time estimates** based on the live workload and opening hours
- **Workload indicator** (Light / Moderate / Busy) for staff and customers
- **Pickup codes** (4 digits) and private tracking codes, plus a printable claim stub
- **🎁 Loyalty reward**: every Nth order is automatically discounted (default: every 10th order, 10% off). Customers see their stamp card.
- **Customer app**: live progress, ready time, pickup code, notifications, change history, an "Ask us" helper, and public tracking without an account
- Automatic **pickup reminders**, plus SMS (simulation by default, Semaphore-ready)
- **📢 Announcement banner** for customers (for example "Closed on Dec 25")

**Owner transparency**
- **Discrepancies** that need the owner's review: weight decreases or changes of 10% or more, price decreases, voided payments, cancellations, status moved backward, laundry released unpaid, and **cash drawer shortages or overages**
- **💵 Cash closing**: staff count the drawer without seeing the expected amount, and the system compares it with the recorded cash payments
- **🧾 Expenses** (detergent, electricity, LPG, rent, salaries…) give you **real profit** and margin
- Reports: revenue, profit, on-time rate, turnaround, sales by service, payment methods, employee performance, busiest hours, repeat customers and unpaid balances. CSV export opens in Excel.
- An **append-only audit log** (protected by database triggers), PIN quick-switch so every action is recorded under the right person, and employee activity tracking

**Security**: hashed passwords and PINs, prepared statements, output escaping, CSRF tokens on every form, role checks, brute-force lockout (per account, so one worker's typos never lock the whole shop), secure sessions, Excel-safe CSV exports, and `.htaccess` protection for `includes/` and `database/`.

---

## 6. Colors (owner or each user)
- **Owner → Settings → 🎨 Colors**: choose from 9 ready-made themes (Pink, Rose, Blush, Lavender, Coral, Mint, Teal, Blue, Charcoal) or pick **any custom color**. The sidebar, buttons and highlights are generated automatically.
- **Each user → My account → My colors**: staff and customers can choose their own colors, or keep "Shop default".
- The owner can switch off "Let each user choose their own colors" so everyone uses the shop colors.

## 7. Demo data
- **Load demo data**: one week of orders, payments, cash closings, expenses and a weight discrepancy. Demo items show a **DEMO** label.
- **Delete all demo data**: removes only demo items (orders, payments, customers, expenses, notifications, demo logs and demo accounts). Your real orders, payments, customers and audit log stay.
- You can load and delete again as many times as you like.

## 8. How to update or change things (no coding needed for most)

| I want to change… | Where |
|---|---|
| Prices, services, minimum kg, turnaround time | Owner > **Services & Pricing** |
| Shop name, address, phone, opening hours | Owner > **Settings** |
| Colors (9 themes or any custom color) | Owner > Settings > Colors · each user: My account |
| Announcement for customers | Owner > Settings |
| Loyalty reward (every N orders, discount %) | Owner > Settings |
| Rush % and speed, shop capacity, reminder timing, discrepancy % | Owner > Settings |
| Employees, passwords, PINs | Owner > **Employees** |
| **Tagalog words** | `includes/lang_tl.php` (English on the left, Tagalog on the right) |
| Menu items or shortcut letters | `includes/layout.php` → `nav_items()` |
| Add a new color theme | `includes/layout.php` → `THEMES` |
| Keywords the order reader understands | `includes/ai.php` → `service_keywords()` |
| Expense categories | `owner/expenses.php` → `EXPENSE_CATEGORIES` |
| Database password, AI key, SMS key, address for QR codes (`PUBLIC_URL`) | `config.php` |

All business rules live in **one file**, `includes/orders.php` (pricing, ready time, loyalty, payments, discrepancies, cash closing), so changes are made in one place.

---

## 9. Folder structure

```
laundryflow/
├── config.php                ← database, AI and SMS settings
├── index.php · login.php · register.php · logout.php · language.php
├── track.php                 ← public tracking
├── account.php · help.php
├── assets/style.css          ← design (colors come from Settings > Theme)
├── includes/
│   ├── bootstrap.php  db.php  helpers.php  auth.php
│   ├── orders.php            ← ALL business rules
│   ├── ai.php                ← AI + offline reader/answers
│   ├── lang.php  lang_tl.php ← Tagalog / English
│   ├── layout.php            ← menu, themes, shared UI
│   ├── qr.php                ← QR code generator for claim stubs
│   └── demo.php              ← demo data
├── customer/  dashboard · orders · order · notifications · help (Ask us)
├── staff/     dashboard (Counter) · new_order · orders · order · order_action · pickups · customers · closing · receipt · switch
├── owner/     dashboard · assistant · reports · transactions · expenses · discrepancies · audit · employees · services · settings · notifications
└── database/  laundryflow.sql · upgrade_v2_to_v3.sql
```

## 10. Database tables
users · customers · services · orders · order_status_history · payments · discrepancies · audit_logs · notifications · settings · login_attempts · **expenses** · **cash_closings**

## 11. Before using it in the real shop
1. Owner → Settings → **Delete all demo data**, then change the owner password.
2. Set `APP_DEBUG` to `false` in `config.php`.
3. Use HTTPS when it's online. Back up the database every week (phpMyAdmin > Export).
4. Do a cash closing at the end of every shift.
