// ==========================================
// Multi-Language System - Thai & English
// Consolidated & Deduplicated Version
// ==========================================

const Lang = {
    // Current language
    current: 'th',
    exchangeRate: 0, // No guessed fallback; conversion stays unavailable until PHP provides a validated rate

    // Available languages
    languages: {
        en: { name: 'English', flag: '🇬🇧' },
        th: { name: 'ไทย', flag: '🇹🇭' }
    },

    // Translation dictionary
    translations: {
        // ==========================================
        // Common & General
        // ==========================================
        'common.search': { en: 'Search', th: 'ค้นหา' },
        'common.loading': { en: 'Loading...', th: 'กำลังโหลด...' },
        'common.clear': { en: 'Clear', th: 'ล้าง' },
        'common.all': { en: 'All', th: 'ทั้งหมด' },
        'common.categories': { en: 'Categories:', th: 'หมวดหมู่:' },
        'common.no_products_found': { en: 'No products found', th: 'ไม่พบสินค้า' },
        'common.clear_filters': { en: 'Clear filters', th: 'ล้างตัวกรอง' },
        'common.sold_out': { en: 'Sold Out', th: 'สินค้าหมด' },
        'common.confirm': { en: 'Confirm', th: 'ยืนยัน' },
        'common.cancel': { en: 'Cancel', th: 'ยกเลิก' },
        'common.save': { en: 'Save', th: 'บันทึก' },
        'common.close': { en: 'Close', th: 'ปิด' },
        'common.edit': { en: 'Edit', th: 'แก้ไข' },
        'common.delete': { en: 'Delete', th: 'ลบ' },
        'common.add': { en: 'Add', th: 'เพิ่ม' },
        'common.update': { en: 'Update', th: 'อัปเดต' },
        'common.actions': { en: 'Actions', th: 'การดำเนินการ' },
        'common.action': { en: 'Action', th: 'การดำเนินการ' },
        'common.id': { en: 'ID', th: 'ไอดี' },
        'common.date': { en: 'Date:', th: 'วันที่:' },
        'common.product_name': { en: 'Product Name:', th: 'ชื่อสินค้า:' },
        'common.key_code': { en: 'Key Code:', th: 'รหัสคีย์:' },
        'common.amount_paid': { en: 'Amount Paid:', th: 'จำนวนที่จ่าย:' },
        'common.copy_all': { en: 'Copy All', th: 'คัดลอกทั้งหมด' },
        'common.download': { en: 'Download File', th: 'ดาวน์โหลดไฟล์' },
        'common.copy_success': { en: 'Copied!', th: 'คัดลอกแล้ว!' },
        'common.insufficient_balance': { en: 'Insufficient balance!', th: 'ยอดเงินไม่เพียงพอ!' },
        'common.copied': { en: 'Copied!', th: 'คัดลอกแล้ว!' },
        'common.copy': { en: 'Copy', th: 'คัดลอก' },
        'common.copy_failed': { en: 'Copy failed', th: 'คัดลอกไม่สำเร็จ' },
        'common.copy_manually': { en: 'Please manually copy the text.', th: 'กรุณาคัดลอกข้อความด้วยตนเอง' },
        'common.confirm_delete_item': { en: 'Are you sure you want to delete this item?', th: 'ยืนยันการลบรายการนี้หรือไม่?' },
        'common.error': { en: 'Error', th: 'เกิดข้อผิดพลาด' },
        'common.success': { en: 'Success', th: 'สำเร็จ' },
        'common.verifying': { en: 'Verifying...', th: 'กำลังตรวจสอบ...' },
        'common.balance': { en: 'Balance', th: 'ยอดเงิน' },
        'common.new_balance': { en: 'New Balance', th: 'ยอดเงินคงเหลือใหม่' },
        'common.convert_to': { en: 'Convert to', th: 'แปลงเป็น' },
        'common.user': { en: 'User', th: 'ผู้ใช้' },
        'common.reseller': { en: 'Reseller', th: 'รีเซลเลอร์' },
        'common.pause': { en: 'Pause', th: 'หยุดชั่วคราว' },
        'common.activate': { en: 'Activate', th: 'เปิดใช้งาน' },
        'common.filter': { en: 'Filter', th: 'กรองข้อมูล' },
        'common.preview': { en: 'Preview', th: 'ดูตัวอย่าง' },
        'common.available': { en: 'Available', th: 'พร้อมใช้งาน' },
        'common.sold': { en: 'Sold', th: 'ขายแล้ว' },
        'common.password': { en: 'Password', th: 'รหัสผ่าน' },
        'common.amount': { en: 'Amount', th: 'จำนวนเงิน' },
        'common.search_placeholder': { en: 'Search products...', th: 'ค้นหาสินค้า...' },
        'common.table.product': { en: 'Product', th: 'สินค้า' },
        'common.table.duration': { en: 'Duration', th: 'ระยะเวลา' },
        'common.table.key': { en: 'Key Code', th: 'รหัสคีย์' },
        'common.table.price': { en: 'Price Paid', th: 'ราคาที่จ่าย' },
        'common.table.date': { en: 'Purchase Date', th: 'วันที่ซื้อ' },
        'common.days': { en: 'Days', th: 'วัน' },

        // ==========================================
        // Navigation
        // ==========================================
        'nav.home': { en: 'Home', th: 'หน้าแรก' },
        'nav.dashboard': { en: 'Dashboard', th: 'แดชบอร์ด' },
        'nav.users': { en: 'Users', th: 'ผู้ใช้งาน' },
        'nav.security': { en: 'Security', th: 'ความปลอดภัย' },
        'nav.resellers': { en: 'Resellers', th: 'รีเซลเลอร์' },
        'nav.products': { en: 'Products', th: 'สินค้า' },
        'nav.keys': { en: 'Keys', th: 'คีย์' },
        'nav.transactions': { en: 'Transactions', th: 'ธุรกรรม' },
        'nav.profit': { en: 'Profit', th: 'กำไร' },
        'nav.codes': { en: 'Codes', th: 'โค้ด' },
        'nav.settings': { en: 'Settings', th: 'ตั้งค่า' },
        'nav.logout': { en: 'Logout', th: 'ออกจากระบบ' },
        'nav.balance': { en: 'Balance:', th: 'ยอดเงิน:' },
        'nav.deposit': { en: 'Deposit', th: 'เติมเงิน' },
        'nav.buy': { en: 'Buy', th: 'ซื้อ' },
        'nav.api_store': { en: 'API Products', th: 'สินค้า API' },
        'nav.cheatgame_api': { en: 'CHEATGAME API', th: 'CHEATGAME API' },
        'nav.my_keys': { en: 'My Keys', th: 'คีย์ของฉัน' },
        'nav.history': { en: 'History', th: 'ประวัติ' },
        'nav.account': { en: 'Account', th: 'บัญชี' },
        'nav.admin': { en: 'Admin', th: 'ผู้ดูแล' },
        'nav.user': { en: 'User', th: 'ผู้ใช้' },
        'nav.more': { en: 'More', th: 'เพิ่มเติม' },
        'nav.rankings': { en: 'Rankings', th: 'จัดอันดับ' },
        'ranking.title': { en: 'Rank Arena', th: 'สนามจัดอันดับ' },
        'ranking.subtitle': { en: 'Compete throughout the month and build your all-time standing.', th: 'แข่งขันตลอดเดือนและสะสมอันดับตลอดกาล' },
        'ranking.monthly_title': { en: 'Monthly User Rankings', th: 'อันดับผู้ใช้ประจำเดือน' },
        'ranking.monthly_desc': { en: 'Top 10 users for the current calendar month.', th: 'ผู้ใช้อันดับสูงสุด 10 คนของเดือนปัจจุบัน' },
        'ranking.lifetime_title': { en: 'All-Time Deposit Rankings', th: 'อันดับเติมเงินสะสมตลอดกาล' },
        'ranking.lifetime_desc': { en: 'Users and resellers compete together. Administrators are excluded.', th: 'ผู้ใช้และตัวแทนแข่งขันร่วมกัน โดยไม่รวมแอดมิน' },
        'ranking.my_rank': { en: 'Your Current Rank', th: 'แรงค์ปัจจุบันของคุณ' },
        'ranking.my_position': { en: 'Your Position', th: 'อันดับของคุณ' },
        'ranking.position': { en: 'Position', th: 'อันดับ' },
        'ranking.not_ranked': { en: 'Not ranked yet', th: 'ยังไม่มีอันดับ' },
        'ranking.rank.unranked': { en: 'Unranked', th: 'ยังไม่มีแรงค์' },
        'ranking.rank.bronze': { en: 'Bronze', th: 'Bronze' },
        'ranking.rank.silver': { en: 'Silver', th: 'Silver' },
        'ranking.rank.gold': { en: 'Gold', th: 'Gold' },
        'ranking.rank.platinum': { en: 'Platinum', th: 'Platinum' },
        'ranking.month_progress_title': { en: "This Month's Rank Progress", th: 'ความคืบหน้าแรงค์เดือนนี้' },
        'ranking.month_progress_desc': { en: 'Check how much you have deposited this month and how close you are to the next rank.', th: 'ตรวจสอบยอดเติมเดือนนี้และดูว่าเหลืออีกเท่าไรจึงจะขึ้นแรงค์ถัดไป' },
        'ranking.month_deposit_amount': { en: 'Rank-qualifying deposits this month', th: 'ยอดเติมที่นับเข้าแรงค์เดือนนี้' },
        'ranking.rank_bonus': { en: 'Rank bonus', th: 'โบนัสแรงค์' },
        'ranking.next_rank_target': { en: 'Next rank target', th: 'เป้าหมายแรงค์ถัดไป' },
        'ranking.remaining_to_next': { en: 'Amount remaining', th: 'ยอดที่เหลืออีก' },
        'ranking.progress': { en: 'Progress', th: 'ความคืบหน้า' },
        'ranking.remaining_percent': { en: 'Remaining', th: 'เหลืออีก' },
        'ranking.max_rank': { en: 'Highest rank', th: 'แรงค์สูงสุด' },
        'ranking.max_rank_reached': { en: 'You have reached the highest rank for this month.', th: 'คุณขึ้นถึงแรงค์สูงสุดของเดือนนี้แล้ว' },
        'ranking.bonus_next_deposit_note': { en: 'Your current rank bonus applies to the next eligible completed deposit.', th: 'โบนัสของแรงค์ปัจจุบันจะใช้กับรายการเติมเงินที่สำเร็จและเข้าเงื่อนไขครั้งถัดไป' },
        'ranking.role.user': { en: 'User', th: 'ผู้ใช้งาน' },
        'ranking.role.reseller': { en: 'Reseller', th: 'ตัวแทน' },
        'ranking.role.admin': { en: 'Administrator', th: 'แอดมิน' },
        'ranking.score': { en: 'Power', th: 'พลังสะสม' },
        'ranking.amount': { en: 'Total deposited', th: 'ยอดเติมสะสม' },
        'ranking.lifetime_amount': { en: 'Your all-time deposits', th: 'ยอดเติมสะสมของคุณ' },
        'ranking.reset_note': { en: 'Monthly rankings start fresh on the 1st for everyone.', th: 'อันดับประจำเดือนเริ่มใหม่พร้อมกันทุกคนในวันที่ 1' },
        'ranking.privacy_note': { en: 'Usernames are partially hidden.', th: 'ชื่อผู้ใช้ถูกปิดบางส่วน' },
        'ranking.public_amount_note': { en: 'All-time deposit totals are public while usernames remain partially hidden.', th: 'ยอดเติมสะสมแสดงแบบสาธารณะ แต่ชื่อผู้ใช้ยังถูกปิดบางส่วน' },
        'ranking.empty': { en: 'No ranking data yet.', th: 'ยังไม่มีข้อมูลการจัดอันดับ' },
        'ranking.bonus_received': { en: 'Rank bonus', th: 'โบนัสแรงค์' },
        'ranking.open_board': { en: 'Open Rankings', th: 'เปิดกระดานจัดอันดับ' },
        'ranking.current_user': { en: 'You', th: 'คุณ' },
        'ranking.admin.title': { en: 'Ranking & Bonus Audit', th: 'ตรวจสอบอันดับและโบนัส' },
        'ranking.admin.awards': { en: 'Recent Bonus Decisions', th: 'การตัดสินโบนัสล่าสุด' },
        'ranking.admin.no_awards': { en: 'No bonus decisions yet.', th: 'ยังไม่มีรายการตัดสินโบนัส' },
        'ranking.admin.deposit_tx': { en: 'Deposit Transaction', th: 'ธุรกรรมเติมเงิน' },
        'ranking.admin.base': { en: 'Base Deposit', th: 'ยอดเติมหลัก' },
        'ranking.admin.bonus': { en: 'Bonus', th: 'โบนัส' },
        'ranking.admin.status': { en: 'Decision', th: 'ผลการตัดสิน' },
        'ranking.admin.readonly_desc': { en: 'Read-only financial audit for rank decisions and bonus transactions.', th: 'หน้าตรวจสอบการตัดสินแรงค์และธุรกรรมโบนัสแบบอ่านอย่างเดียว' },
        'ranking.admin.monthly_entries': { en: 'Monthly board entries', th: 'รายการบนบอร์ดรายเดือน' },
        'ranking.admin.lifetime_entries': { en: 'Lifetime board entries', th: 'รายการบนบอร์ดตลอดกาล' },
        'ranking.admin.applied_month': { en: 'Bonuses applied this month', th: 'โบนัสที่จ่ายเดือนนี้' },
        'ranking.admin.paid_month': { en: 'Bonus paid this month', th: 'ยอดโบนัสเดือนนี้' },
        'ranking.admin.user': { en: 'User', th: 'ผู้ใช้' },
        'ranking.admin.rank': { en: 'Rank', th: 'แรงค์' },
        'ranking.admin.rank_used': { en: 'Benefit rank used', th: 'แรงค์ที่ใช้คำนวณ' },
        'ranking.admin.date': { en: 'Date', th: 'วันที่' },
        'ranking.admin.monthly_total': { en: 'Monthly qualifying total', th: 'ยอดสะสมเข้าแรงค์เดือนนี้' },
        'ranking.admin.lifetime_total': { en: 'All-time deposit total', th: 'ยอดเติมสะสมทั้งหมด' },
        'ranking.admin.monthly_board': { en: 'Complete Monthly Rank Board', th: 'กระดานแรงค์รายเดือนทั้งหมด' },
        'ranking.admin.lifetime_board': { en: 'Complete All-Time Deposit Board', th: 'กระดานเติมเงินสะสมทั้งหมด' },
        'ranking.admin.ledger': { en: 'Deposit Qualification Ledger', th: 'บัญชีรายการเติมเงินที่ใช้จัดอันดับ' },
        'ranking.admin.ledger_desc': { en: 'Every recorded deposit used by rankings, including source transaction status for reconciliation.', th: 'รายการเติมเงินทุกแถวที่ระบบจัดอันดับบันทึกไว้ พร้อมสถานะธุรกรรมต้นทางสำหรับตรวจสอบ' },
        'ranking.admin.bonus_desc': { en: 'Every bonus decision, including deposits that received no bonus.', th: 'การตัดสินโบนัสทุกครั้ง รวมถึงรายการที่ไม่ได้รับโบนัส' },
        'ranking.admin.user_id': { en: 'User ID', th: 'รหัสผู้ใช้' },
        'ranking.admin.username': { en: 'Full username', th: 'ชื่อผู้ใช้เต็ม' },
        'ranking.admin.role': { en: 'Role', th: 'ประเภทบัญชี' },
        'ranking.admin.last_deposit': { en: 'Last deposit', th: 'เติมล่าสุด' },
        'ranking.admin.credited_amount': { en: 'Credited amount', th: 'ยอดเครดิตจริง' },
        'ranking.admin.qualifying_thb': { en: 'Qualifying THB', th: 'ยอดที่ใช้จัดอันดับ (บาท)' },
        'ranking.admin.source': { en: 'Source', th: 'ช่องทาง' },
        'ranking.admin.method': { en: 'Conversion method', th: 'วิธีคำนวณเป็นบาท' },
        'ranking.admin.tx_status': { en: 'Transaction status', th: 'สถานะธุรกรรม' },
        'ranking.admin.account_status': { en: 'Account status', th: 'สถานะบัญชี' },
        'ranking.admin.description': { en: 'Description', th: 'รายละเอียด' },
        'ranking.admin.period': { en: 'Rank period', th: 'รอบแรงค์' },
        'ranking.admin.bonus_tx': { en: 'Bonus transaction', th: 'ธุรกรรมโบนัส' },
        'ranking.admin.qualifying_total': { en: 'Monthly total after deposit', th: 'ยอดสะสมเดือนหลังรายการ' },
        'ranking.admin.records': { en: 'records', th: 'รายการ' },
        'ranking.admin.previous': { en: 'Previous', th: 'ก่อนหน้า' },
        'ranking.admin.next': { en: 'Next', th: 'ถัดไป' },
        'ranking.admin.page': { en: 'Page', th: 'หน้า' },
        'ranking.admin.of': { en: 'of', th: 'จาก' },
        'ranking.admin.no_ledger': { en: 'No deposit ledger records yet.', th: 'ยังไม่มีรายการในบัญชีจัดอันดับ' },
        'ranking.admin.no_board': { en: 'No board entries yet.', th: 'ยังไม่มีรายการบนกระดาน' },
        'ranking.admin.ledger_id': { en: 'Ledger ID', th: 'รหัสบัญชีรายการ' },
        'ranking.admin.award_id': { en: 'Award ID', th: 'รหัสการตัดสินโบนัส' },
        'ranking.admin.current_role': { en: 'Current role', th: 'ประเภทปัจจุบัน' },
        'ranking.admin.updated': { en: 'Updated', th: 'อัปเดตล่าสุด' },
        'ranking.admin.raw_status.completed': { en: 'Completed', th: 'สำเร็จ' },
        'ranking.admin.raw_status.pending': { en: 'Pending', th: 'รอดำเนินการ' },
        'ranking.admin.raw_status.failed': { en: 'Failed', th: 'ล้มเหลว' },
        'ranking.admin.raw_status.processing': { en: 'Processing', th: 'กำลังดำเนินการ' },
        'ranking.admin.raw_status.active': { en: 'Active', th: 'ใช้งานอยู่' },
        'ranking.admin.raw_status.banned': { en: 'Banned', th: 'ถูกระงับ' },
        'ranking.admin.raw_status.missing': { en: 'Missing record', th: 'ไม่พบข้อมูลต้นทาง' },
        'ranking.admin.raw_status.unknown': { en: 'Unknown', th: 'ไม่ทราบสถานะ' },
        'ranking.admin.status.applied': { en: 'Applied', th: 'จ่ายแล้ว' },
        'ranking.admin.status.not_eligible': { en: 'No bonus', th: 'ยังไม่ได้โบนัส' },
        'ranking.admin.status.processing': { en: 'Processing', th: 'กำลังดำเนินการ' },
        'ranking.admin.status.unknown': { en: 'Unknown', th: 'ไม่ทราบสถานะ' },
        'ranking.error.unavailable': { en: 'The ranking system is temporarily unavailable. Please try again or contact the administrator.', th: 'ระบบจัดอันดับยังไม่พร้อม กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ' },
        'nav.special_prices': { en: 'Special Prices', th: 'ราคาพิเศษ' },
        'nav.out_of_stock': { en: 'Out of Stock', th: 'สินค้าหมดสต๊อก' },
        'nav.product_keys': { en: 'Product Keys', th: 'คีย์สินค้า' },
        'nav.binance': { en: 'Binance', th: 'Binance' },
        'nav.dashboard_short': { en: 'Dash', th: 'แดช' },
        'nav.products_short': { en: 'Prod', th: 'สินค้า' },
        'nav.keys_short': { en: 'Keys', th: 'คีย์' },
        'nav.users_short': { en: 'Users', th: 'ผู้ใช้' },
        'nav.transactions_short': { en: 'Trans', th: 'ธุรกรรม' },
        'nav.settings_short': { en: 'Set', th: 'ตั้งค่า' },
        'nav.more_short': { en: 'More', th: 'เพิ่ม' },

        // ==========================================
        // Login & Register
        // ==========================================
        'login.button': { en: 'Login', th: 'เข้าสู่ระบบ' },
        'register.button': { en: 'Register', th: 'ลงทะเบียน' },

        // ==========================================
        // User/Reseller Dashboard
        // ==========================================
        'dashboard.title': { en: 'Dashboard', th: 'แดชบอร์ด' },
        'dashboard.welcome': { en: 'Welcome back', th: 'ยินดีต้อนรับกลับ' },
        'dashboard.total_users': { en: 'Total Users', th: 'ผู้ใช้ทั้งหมด' },
        'dashboard.total_resellers': { en: 'Total Resellers', th: 'รีเซลเลอร์ทั้งหมด' },
        'dashboard.active_products': { en: 'Active Products', th: 'สินค้าที่ใช้งานอยู่' },
        'dashboard.keys_sold': { en: 'Keys Sold', th: 'คีย์ที่ขายแล้ว' },
        'dashboard.total_income': { en: 'Total Income', th: 'รายได้ทั้งหมด' },
        'dashboard.announcements': { en: 'Announcements', th: 'ประกาศ' },
        'dashboard.stock': { en: 'Stock:', th: 'สต๊อก:' },
        'dashboard.price': { en: 'Price:', th: 'ราคา:' },
        'dashboard.buy_now': { en: 'Buy Now', th: 'ซื้อเลย' },
        'dashboard.added': { en: 'Added:', th: 'เพิ่มเมื่อ:' },
                'dashboard.select_duration': { en: 'Select Duration:', th: 'เลือกระยะเวลา:' },
        'dashboard.keys_bought': { en: 'Total Keys Purchased', th: 'คีย์ที่ซื้อทั้งหมด' },
        'dashboard.keys_count': { en: 'Keys', th: 'คีย์' },
        'dashboard.view_my_keys': { en: 'View My Keys', th: 'ดูคีย์ของฉัน' },
        'dashboard.recent_purchases': { en: 'Recent Purchases', th: 'การซื้อล่าสุด' },
        'dashboard.empty_keys': { en: 'No keys purchased yet', th: 'ยังไม่มีการซื้อคีย์' },
        'dashboard.start_buying': { en: 'Start buying now', th: 'เริ่มซื้อเลย' },


        // ==========================================
        // Buy Modal & Process
        // ==========================================
        'buy.modal.confirm_title': { en: 'Confirm Purchase', th: 'ยืนยันการซื้อ' },
        'buy.modal.price_per_item': { en: 'Price per item:', th: 'ราคาต่อชิ้น:' },
        'buy.modal.quantity': { en: 'Quantity:', th: 'จำนวน:' },
        'buy.modal.total_price': { en: 'Total Price:', th: 'ราคารวม:' },
        'buy.modal.balance_after': { en: 'Balance After:', th: 'ยอดเงินหลังซื้อ:' },

        // ==========================================
        // History Page
        // ==========================================
        'history.total': { en: 'Total', th: 'รวม' },
        'buy.modal.success_title': { en: 'Success!', th: 'สำเร็จ!' },
                'buy.modal.amount_paid': { en: 'Amount Paid:', th: 'จำนวนที่จ่าย:' },
        'buy.purchase_success_title': { en: 'Purchase Success', th: 'การซื้อสำเร็จ' },


        // ==========================================
        // Products Management (Admin)
        // ==========================================
        'admin.products.title': { en: 'Manage Products', th: 'จัดการสินค้า' },
        'admin.products.add_btn': { en: 'Add Product', th: 'เพิ่มสินค้า' },
        'admin.products.edit_btn': { en: 'Edit', th: 'แก้ไข' },
        'admin.products.delete_btn': { en: 'Delete', th: 'ลบ' },
        'admin.products.status.active': { en: 'Active', th: 'ใช้งาน' },
        'admin.products.status.inactive': { en: 'Inactive', th: 'ไม่ใช้งาน' },
        'admin.products.total_keys': { en: 'Total Keys:', th: 'คีย์ทั้งหมด:' },
        'admin.products.available_keys': { en: 'Available:', th: 'พร้อมใช้งาน:' },
        'admin.products.variants': { en: 'Variants', th: 'รูปแบบสินค้า' },

        // ==========================================
        // Users Management (Admin)
        // ==========================================
        'admin.users.title': { en: 'Manage Users', th: 'จัดการผู้ใช้งาน' },
        'admin.users.table.id': { en: 'ID', th: 'ไอดี' },
        'admin.users.table.username': { en: 'Username', th: 'ชื่อผู้ใช้' },
        'admin.users.table.email': { en: 'Email', th: 'อีเมล' },
        'admin.users.table.balance': { en: 'Balance', th: 'ยอดเงิน' },
        'admin.users.table.status': { en: 'Status', th: 'สถานะ' },
        'admin.users.table.joined': { en: 'Joined', th: 'เข้าร่วมเมื่อ' },
        'admin.users.status.active': { en: 'Active', th: 'ปกติ' },
        'admin.users.status.banned': { en: 'Banned', th: 'ถูกแบน' },

        // ==========================================
        // Transactions & Profit
        // ==========================================
        'admin.transactions.title': { en: 'Transactions', th: 'ธุรกรรม' },
        'admin.transactions.table.date': { en: 'Date', th: 'วันที่' },
        'admin.transactions.modal.title': { en: 'License Details', th: 'รายละเอียดไลเซนส์' },
        'admin.transactions.modal.product': { en: 'Product Name:', th: 'ชื่อสินค้า:' },
        'admin.transactions.modal.date': { en: 'Purchase Date:', th: 'วันที่ซื้อ:' },
        'admin.transactions.modal.keys': { en: 'Key Code:', th: 'รหัสคีย์:' },
        'admin.transactions.modal.amount': { en: 'Amount Paid:', th: 'จำนวนที่จ่าย:' },
        'admin.transactions.modal.copy': { en: 'Copy License', th: 'คัดลอกคีย์' },
        'admin.transactions.modal.download': { en: 'Download', th: 'ดาวน์โหลด' },
        'admin.transactions.type.purchase': { en: 'Purchase', th: 'ซื้อสินค้า' },
        'admin.transactions.type.deposit': { en: 'Deposit', th: 'เติมเงิน' },
        'admin.transactions.type.manual_add': { en: 'Manual Add', th: 'เพิ่มเงิน (แอดมิน)' },
        'admin.transactions.type.manual_deduct': { en: 'Manual Deduct', th: 'ลดเงิน (แอดมิน)' },
        'admin.transactions.type.rank_bonus': { en: 'Rank Bonus', th: 'โบนัสแรงค์' },
        'admin.transactions.status.completed': { en: 'Completed', th: 'สำเร็จ' },
        'admin.transactions.status.pending': { en: 'Pending', th: 'รอดำเนินการ' },
        'admin.transactions.status.failed': { en: 'Failed', th: 'ล้มเหลว' },
        'admin.profit.title': { en: 'Profit Analysis', th: 'วิเคราะห์กำไร' },
        'admin.profit.revenue': { en: 'Revenue', th: 'รายได้' },
        'admin.profit.cost': { en: 'Cost', th: 'ต้นทุน' },
        'admin.profit.profit': { en: 'Profit', th: 'กำไร' },

        // ==========================================
        // Reseller Prices (Admin)
        // ==========================================
        'admin.reseller_prices.title': { en: 'Reseller Prices', th: 'ราคารีเซลเลอร์' },
        'admin.reseller_prices.heading': { en: 'Set Reseller Prices', th: 'ตั้งค่าราคารีเซลเลอร์' },
        'admin.reseller_prices.select_label': { en: 'Select Reseller', th: 'เลือกรีเซลเลอร์' },
        'admin.reseller_prices.select_default': { en: '-- Select Reseller --', th: '-- เลือกรีเซลเลอร์ --' },
        'admin.reseller_prices.custom_for': { en: 'Custom prices for {username}', th: 'ราคาพิเศษสำหรับ {username}' },
        'admin.reseller_prices.table.product': { en: 'Product', th: 'สินค้า' },
        'admin.reseller_prices.table.duration': { en: 'Duration', th: 'ระยะเวลา' },
        'admin.reseller_prices.table.default_price': { en: 'Default Price', th: 'ราคาปกติ' },
        'admin.reseller_prices.table.custom_price': { en: 'Custom Price', th: 'ราคาพิเศษ' },
        'admin.reseller_prices.placeholder_default': { en: 'Leave empty to use default', th: 'เว้นว่างเพื่อใช้ราคาปกติ' },
        'admin.reseller_prices.placeholder_duration': { en: 'e.g., 1 day, 1 month, 1 year', th: 'เช่น 1 วัน, 1 เดือน, 1 ปี' },
        'admin.reseller_prices.help_default': { en: 'Leave empty to use default price', th: 'เว้นว่างเพื่อใช้ราคาปกติ' },
        'admin.reseller_prices.save_btn': { en: 'Save Prices', th: 'บันทึกราคา' },
        'admin.reseller_prices.error.invalid_request': { en: 'The reseller pricing request is invalid.', th: 'คำขอตั้งราคาตัวแทนไม่ถูกต้อง' },
        'admin.reseller_prices.error.prepare': { en: 'The reseller pricing table could not be prepared.', th: 'ไม่สามารถเตรียมตารางราคาตัวแทนได้' },
        'admin.reseller_prices.error.save': { en: 'Custom reseller prices could not be saved.', th: 'ไม่สามารถบันทึกราคาพิเศษของตัวแทนได้' },
        'admin.reseller_prices.error.invalid_account': { en: 'The selected account is not a reseller.', th: 'บัญชีที่เลือกไม่ใช่บัญชีตัวแทน' },
        'admin.reseller_prices.success.saved': { en: '{count} custom price(s) were saved.', th: 'บันทึกราคาพิเศษแล้ว {count} รายการ' },
        'admin.reseller_prices.success.cleared': { en: 'Custom prices were cleared. Default reseller prices will be used.', th: 'ล้างราคาพิเศษแล้ว ระบบจะใช้ราคาตัวแทนปกติ' },

        // ==========================================
        // Binance Deposits (Admin)
        // ==========================================
        'admin.binance.title': { en: 'Binance USDT Deposits', th: 'ประวัติการเติมเงิน Binance' },
        'admin.binance.table.user': { en: 'User', th: 'ผู้ใช้งาน' },
        'admin.binance.table.txid': { en: 'TxID', th: 'TxID' },
        'admin.binance.table.rate': { en: 'Rate', th: 'เรทแลกเปลี่ยน' },
        'admin.binance.table.network': { en: 'Network', th: 'เครือข่าย' },
        'admin.binance.table.date': { en: 'Date', th: 'วันที่' },
        'admin.binance.empty': { en: 'No transactions found', th: 'ยังไม่มีรายการ' },

        // ==========================================
        // Categories & Codes (Admin)
        // ==========================================
        'admin.categories.title': { en: 'Manage Categories', th: 'จัดการหมวดหมู่' },
        'admin.categories.table.name': { en: 'Category Name', th: 'ชื่อหมวดหมู่' },
        'admin.categories.table.url': { en: 'Download URL', th: 'ลิงก์ดาวน์โหลด' },
        'admin.categories.empty': { en: 'No categories found', th: 'ไม่พบหมวดหมู่' },
        'admin.codes.title': { en: 'Codes', th: 'โค้ดเติมเงิน' },
        'admin.codes.generate.title': { en: 'Generate Redeem Code', th: 'สร้างโค้ดเติมเงิน' },
        'admin.codes.generate.nominal': { en: 'Nominal', th: 'จำนวนเงิน' },
        'admin.codes.generate.btn': { en: 'Generate', th: 'สร้างโค้ด' },
        'admin.codes.redeem.title': { en: 'Redeem Code', th: 'ใช้โค้ดเติมเงิน' },
        'admin.codes.redeem.btn': { en: 'Redeem', th: 'เติมเงิน' },
        'admin.codes.status.used': { en: 'used', th: 'ใช้แล้ว' },
        'admin.codes.status.unused': { en: 'unused', th: 'ยังไม่ใช้' },

        // ==========================================
        // Admin Keys (admin/keys.php)
        // ==========================================
        'admin.keys.title': { en: 'Manage Keys - Admin Panel', th: 'จัดการคีย์ - แอดมิน' },
        'admin.keys.heading': { en: 'License Key Inventory', th: 'คลังคีย์สินค้า' },
        'admin.keys.subtitle': { en: 'View, search, copy and safely remove local license keys. Prices and key creation are managed from Products.', th: 'สำหรับดู ค้นหา คัดลอก และลบคีย์อย่างปลอดภัยเท่านั้น การเพิ่มคีย์และจัดการราคาให้ทำจากหน้าสินค้า' },
        'admin.keys.filter_product': { en: 'Product', th: 'สินค้า' },
        'admin.keys.filter_status': { en: 'Status', th: 'สถานะ' },
        'admin.keys.all_products': { en: 'All Products', th: 'สินค้าทั้งหมด' },
        'admin.keys.search_label': { en: 'Search', th: 'ค้นหา' },
        'admin.keys.search_placeholder': { en: 'Key, product, username, email, user ID, transaction or API order...', th: 'คีย์, สินค้า, ชื่อผู้ใช้, อีเมล, User ID, Transaction หรือ API Order...' },
        'admin.keys.table.key_code': { en: 'Key Code', th: 'รหัสคีย์' },
        'admin.keys.table.product': { en: 'Product', th: 'สินค้า' },
        'admin.keys.table.buyer': { en: 'Buyer', th: 'ผู้ซื้อ' },
        'admin.keys.table.buyer_id': { en: 'Buyer ID', th: 'User ID ผู้ซื้อ' },
        'admin.keys.table.added_at': { en: 'Added', th: 'วันที่ลง' },
        'admin.keys.table.sold_at': { en: 'Sold', th: 'วันที่ขาย' },
        'admin.keys.table.deleted_at': { en: 'Deleted', th: 'วันที่ลบ' },
        'admin.keys.table.source': { en: 'Sale Source', th: 'แหล่งที่ขาย' },
        'admin.keys.table.transaction': { en: 'Transaction', th: 'Transaction' },
        'admin.keys.table.paid': { en: 'Paid', th: 'ยอดที่จ่าย' },
        'admin.keys.table.store_order': { en: 'Store API Order', th: 'Store API Order' },
        'admin.keys.table.external_ref': { en: 'External Ref', th: 'External Ref' },
        'admin.keys.table.api_client': { en: 'API Client', th: 'API Client' },
        'admin.keys.status.available': { en: 'Available', th: 'พร้อมใช้งาน' },
        'admin.keys.status.sold': { en: 'Sold', th: 'ขายแล้ว' },
        'admin.keys.status.deleted': { en: 'Deleted', th: 'ลบแล้ว' },
        'admin.keys.source.inventory': { en: 'Inventory', th: 'สต็อก' },
        'admin.keys.source.local': { en: 'Website purchase', th: 'ซื้อผ่านเว็บไซต์' },
        'admin.keys.source.store_api': { en: 'Store API', th: 'Store API' },
        'admin.keys.source.legacy': { en: 'Legacy / Unknown', th: 'ข้อมูลเก่า / ไม่ทราบที่มา' },
        'admin.keys.summary.available': { en: 'Available', th: 'พร้อมใช้' },
        'admin.keys.summary.sold': { en: 'Sold', th: 'ขายแล้ว' },
        'admin.keys.summary.deleted': { en: 'Deleted', th: 'ลบแล้ว' },
        'admin.keys.results': { en: 'Showing {from}-{to} of {total} keys', th: 'แสดง {from}-{to} จากทั้งหมด {total} คีย์' },
        'admin.keys.empty': { en: 'No license keys found.', th: 'ไม่พบคีย์สินค้า' },
        'admin.keys.deleted_hint': { en: 'Deleted records are audit snapshots and do not return to live stock.', th: 'รายการที่ลบเป็นข้อมูลตรวจสอบย้อนหลัง และจะไม่กลับเข้าไปในสต็อก' },
        'admin.keys.view_details': { en: 'Details', th: 'รายละเอียด' },
        'admin.keys.details_title': { en: 'Key Details', th: 'รายละเอียดคีย์' },
        'admin.keys.bulk_delete': { en: 'Delete Selected', th: 'ลบที่เลือก' },
        'admin.keys.selected': { en: 'selected', th: 'รายการที่เลือก' },
        'admin.keys.select_at_least_one': { en: 'Please select at least one key.', th: 'กรุณาเลือกอย่างน้อย 1 รายการ' },
        'admin.keys.confirm_bulk_delete': { en: 'Delete {count} selected key(s)? Keys with purchase history will remain in customer history.', th: 'ลบคีย์ที่เลือก {count} รายการหรือไม่? คีย์ที่มีประวัติซื้อจะยังคงอยู่ในประวัติลูกค้า' },
        'admin.keys.delete_title': { en: 'Delete Key', th: 'ลบคีย์' },
        'admin.keys.delete_reason': { en: 'Reason', th: 'เหตุผลในการลบ' },
        'admin.keys.delete_available_warning': { en: 'This key has no purchase history. It will be removed from live stock and an Admin audit snapshot will be kept.', th: 'คีย์นี้ยังไม่มีประวัติการซื้อ ระบบจะลบออกจากสต็อกจริง และเก็บข้อมูลสำหรับตรวจสอบของแอดมินไว้' },
        'admin.keys.delete_sold_warning': { en: 'This key has purchase history. It will be removed only from the Admin inventory view; the live key row and customer purchase history will be preserved.', th: 'คีย์นี้มีประวัติการซื้อ ระบบจะลบออกจากหน้าคลังของแอดมินเท่านั้น โดยยังเก็บข้อมูลคีย์และประวัติการซื้อของลูกค้าไว้ครบ' },
        'admin.keys.confirm_delete_btn': { en: 'Confirm Delete', th: 'ยืนยันการลบ' },
        'admin.keys.reason.wrong_entry': { en: 'Entered by mistake', th: 'ใส่คีย์ผิด' },
        'admin.keys.reason.unusable': { en: 'Key is unusable', th: 'คีย์ใช้งานไม่ได้' },
        'admin.keys.reason.no_longer_needed': { en: 'No longer needed', th: 'ไม่ต้องการใช้งานแล้ว' },
        'admin.keys.reason.other': { en: 'Other', th: 'อื่นๆ' },
        'admin.keys.reason.bulk_delete': { en: 'Bulk delete', th: 'ลบหลายรายการ' },
        'admin.keys.copied': { en: 'Key copied', th: 'คัดลอกคีย์แล้ว' },
        'admin.keys.success.available_deleted': { en: 'Available key removed from live stock. An Admin audit snapshot was kept.', th: 'ลบคีย์ที่ยังไม่ขายออกจากสต็อกแล้ว และเก็บข้อมูลตรวจสอบของแอดมินไว้' },
        'admin.keys.success.sold_archived': { en: 'Sold key removed from the Admin inventory view. Customer purchase history was preserved.', th: 'ลบคีย์ที่ขายแล้วออกจากหน้าคลังแอดมิน โดยประวัติการซื้อของลูกค้ายังคงอยู่ครบ' },
        'admin.keys.success.bulk': { en: 'Removed {deleted} available key(s); archived {archived} history-linked key(s) without affecting customer history.', th: 'ลบคีย์ที่ยังไม่ขาย {deleted} รายการ และซ่อนคีย์ที่มีประวัติการซื้อ {archived} รายการ โดยไม่กระทบประวัติลูกค้า' },
        'admin.keys.success.bulk_failed': { en: '{failed} item(s) could not be removed.', th: 'มี {failed} รายการที่ไม่สามารถลบได้' },
        'admin.keys.error.already_deleted': { en: 'This key was already removed from the Admin inventory.', th: 'คีย์นี้ถูกลบออกจากคลังของแอดมินไปแล้ว' },
        'admin.keys.error.bulk_limit': { en: 'You can delete up to 100 selected keys at one time.', th: 'ลบคีย์ที่เลือกได้สูงสุด 100 รายการต่อครั้ง' },
        'admin.keys.error.delete_failed': { en: 'Unable to delete this key safely. No purchase history was removed.', th: 'ไม่สามารถลบคีย์ได้อย่างปลอดภัย โดยไม่มีประวัติการซื้อใดถูกลบ' },
        'admin.keys.error.journal': { en: 'The key deletion audit journal is unavailable. Deletion is disabled until it is ready.', th: 'ระบบบันทึกข้อมูลคีย์ที่ลบยังไม่พร้อม จึงปิดการลบไว้ชั่วคราวเพื่อป้องกันข้อมูลสูญหาย' },
        'admin.keys.error.not_found': { en: 'Key not found.', th: 'ไม่พบคีย์นี้' },

        // ==========================================
        // Reseller navbar extras
        // ==========================================
        'reseller.nav.more': { en: 'More', th: 'เพิ่มเติม' },
        'reseller.nav.reseller_panel': { en: 'RESELLER', th: 'รีเซลเลอร์' },


        // ==========================================
        // Deposit Methods
        // ==========================================
        'deposit.binance.network': { en: 'Network: TRC20 (Tron)', th: 'เครือข่าย: TRC20 (Tron)' },
        'deposit.binance.rule1': { en: 'Only USDT (Tether) supported.', th: 'รองรับเฉพาะ USDT (Tether)' },

        // User-facing generic errors (frontend)
        // User-facing generic errors (frontend)
        'deposit.alert.copy_success': { en: 'Copied successfully', th: 'คัดลอกสำเร็จ' },
        'deposit.binance.verifying': { en: 'Verifying Transaction...', th: 'กำลังตรวจสอบ Transaction...' },
        'deposit.status.verifying': { en: 'Verifying slip...', th: 'กำลังตรวจสอบข้อมูลสลิป...' },

        // ==========================================
        // Admin Settings
        // ==========================================
        'admin.settings.title': { en: 'Settings', th: 'ตั้งค่า' },
        'admin.settings.default_language': { en: 'Default Language', th: 'ภาษาเริ่มต้นของเว็บ' },
        'admin.settings.default_language_hint': { en: 'Applies as site default until the user changes language.', th: 'มีผลเป็นค่าเริ่มต้นของเว็บ จนกว่าผู้ใช้จะกดเปลี่ยนภาษาเอง' },

        // Store Branding

        // Payment settings

        // PromptPay & Slip Verify
        'admin.settings.promptpay_title': { en: 'PromptPay QR Code', th: 'ตั้งค่าพร้อมเพย์ QR Code' },
        'admin.settings.enable_promptpay': { en: 'Enable PromptPay', th: 'เปิดใช้งานพร้อมเพย์' },
        'admin.settings.account_number': { en: 'Account Number', th: 'หมายเลขบัญชี' },
        'admin.settings.receiver_name': { en: 'Receiver Name', th: 'ชื่อบัญชีรับเงิน' },
        'admin.settings.save_promptpay': { en: 'Save PromptPay', th: 'บันทึกการตั้งค่าพร้อมเพย์' },

        // TrueMoney & EasySlip
        'admin.settings.truemoney_title': { en: 'TrueMoney Angpao', th: 'ซองอั่งเปา TrueMoney' },
        'admin.settings.wallet_number': { en: 'Wallet Number', th: 'หมายเลขวอลเล็ท' },
        'admin.settings.easyslip_title': { en: 'EasySlip API', th: 'EasySlip API' },
        'admin.settings.easyslip_api_key': { en: 'API Key', th: 'API Key' },
        'admin.settings.receiver_name_th': { en: 'Receiver Name (Thai)', th: 'ชื่อผู้รับเงิน (ไทย)' },
        'admin.settings.receiver_name_en': { en: 'Receiver Name (English)', th: 'ชื่อผู้รับเงิน (English)' },

        // Binance USDT
        'admin.settings.binance_title': { en: 'Binance USDT (TRC20)', th: 'ฝากเงิน USDT (Binance)' },
        'admin.settings.binance_wallet': { en: 'Wallet Address', th: 'ที่อยู่กระเป๋าเงิน' },
        'admin.settings.binance_api_key': { en: 'API Key', th: 'Binance API Key' },
        'admin.settings.binance_secret_key': { en: 'Secret Key', th: 'Binance Secret Key' },
        'admin.settings.save_binance': { en: 'Save Binance Settings', th: 'บันทึกการตั้งค่า Binance' },

        // Announcement
        'admin.settings.announcement_text': { en: 'Announcement Text', th: 'ข้อความประกาศ' },
        'admin.settings.help.announcement_text': { en: 'Enter the announcement shown to users on the website.', th: 'กรอกข้อความที่จะประกาศให้ผู้ใช้เห็นบนเว็บไซต์' },
        'admin.settings.help.announcement_active': { en: 'Show this announcement on the website.', th: 'เปิดแสดงประกาศนี้บนเว็บไซต์' },
        'admin.settings.help.announcement_inactive': { en: 'Hide this announcement while keeping its text.', th: 'ซ่อนประกาศนี้โดยยังเก็บข้อความไว้' },
        'admin.settings.save_announcement': { en: 'Save Announcement', th: 'บันทึกประกาศ' },

        // Account
        'admin.settings.account_title': { en: 'Account Settings', th: 'ตั้งค่าบัญชี' },
        'admin.settings.new_password': { en: 'New Password', th: 'รหัสผ่านใหม่' },
        'admin.settings.current_password': { en: 'Current Password', th: 'รหัสผ่านปัจจุบัน' },
        'admin.settings.save_account': { en: 'Update Account', th: 'อัปเดตบัญชี' },

        // ==========================================
        // Account (shared user/reseller pages)
        // ==========================================
        'account.title': { en: 'Account Settings', th: 'ตั้งค่าบัญชี' },
        'account.username': { en: 'Username', th: 'ชื่อผู้ใช้' },
        'account.email': { en: 'Email', th: 'อีเมล' },
        'account.new_password': { en: 'New Password (optional)', th: 'รหัสผ่านใหม่ (ไม่บังคับ)' },
        'account.new_password_placeholder': { en: 'Leave blank to keep current', th: 'เว้นว่างเพื่อใช้รหัสเดิม' },
        'account.confirm_new_password': { en: 'Confirm New Password', th: 'ยืนยันรหัสผ่านใหม่' },
        'account.confirm_new_password_placeholder': { en: 'Re-enter new password', th: 'กรอกรหัสผ่านใหม่อีกครั้ง' },
        'account.current_password': { en: 'Current Password (required)', th: 'รหัสผ่านปัจจุบัน (จำเป็น)' },
        'account.current_password_hint': { en: 'Required to change username/email/password.', th: 'ต้องกรอกรหัสผ่านปัจจุบันเพื่อเปลี่ยนชื่อผู้ใช้/อีเมล/รหัสผ่าน' },
        'account.save_changes': { en: 'Save Changes', th: 'บันทึกการเปลี่ยนแปลง' },

        // ==========================================
        // Admin Binance stats + misc keys
        // ==========================================
        'admin.binance.clear': { en: 'Clear', th: 'ล้าง' },
        'admin.binance.search_placeholder': { en: 'Search TxID or Username...', th: 'ค้นหา TxID หรือชื่อผู้ใช้...' },
        'admin.binance.stats.total_records': { en: 'Total Records', th: 'รายการทั้งหมด' },
        'admin.binance.stats.total_usdt': { en: 'Total USDT', th: 'รวม USDT' },
        'admin.binance.stats.total_thb': { en: 'Total THB', th: 'รวม THB' },

        // ==========================================
        // Admin Categories extras
        // ==========================================
        'admin.categories.heading': { en: 'Category Download Links', th: 'ลิงก์ดาวน์โหลดตามหมวดหมู่' },
        'admin.categories.url_placeholder': { en: 'https://example.com/download', th: 'https://example.com/download' },
        'admin.categories.help.title': { en: 'How it works', th: 'วิธีการทำงาน' },
        'admin.categories.help.desc': { en: 'Categories here are pulled from products. Setting a URL shows a download button after purchase.', th: 'รายการหมวดหมู่ดึงจากสินค้าในระบบ เมื่อตั้งลิงก์แล้วจะมีปุ่มดาวน์โหลดหลังการซื้อ' },

        // ==========================================
        // Admin Codes extras
        // ==========================================
        'admin.codes.heading': { en: 'Codes', th: 'โค้ดเติมเงิน' },
        'admin.codes.generate.result': { en: 'Generated Code', th: 'โค้ดที่สร้าง' },
        'admin.codes.generate.help': { en: 'This code can be redeemed by User / Reseller / Admin once.', th: 'โค้ดนี้ใช้เติมเงินได้ 1 ครั้ง (ผู้ใช้/รีเซลเลอร์/แอดมิน)' },
        'admin.codes.redeem.code': { en: 'Code', th: 'โค้ด' },
        'admin.codes.redeem.warning': { en: 'Warning: 10 wrong attempts will suspend the account.', th: 'คำเตือน: กรอกผิด 10 ครั้ง บัญชีจะถูกระงับ' },
        'admin.codes.recent.title': { en: 'Recent Generated Codes', th: 'โค้ดที่สร้างล่าสุด' },
        'admin.codes.recent.empty': { en: 'No codes yet.', th: 'ยังไม่มีโค้ด' },
        'admin.codes.table.used_by': { en: 'Used By', th: 'ผู้ใช้โค้ด' },
        'admin.codes.table.created': { en: 'Created', th: 'วันที่สร้าง' },

        // ==========================================
        // Admin Deposit (admin/deposit.php)
        // ==========================================
        'admin.deposit.title': { en: 'Deposit', th: 'เติมเงิน' },
        'admin.deposit.heading': { en: 'Deposit', th: 'เติมเงิน' },
        'admin.deposit.my_balance': { en: 'My Balance: {balance}', th: 'ยอดเงินของฉัน: {balance}' },
        'admin.deposit.add_balance': { en: 'Add Balance', th: 'เพิ่มยอดเงิน' },
        'admin.deposit.redeem.title': { en: 'Redeem Code', th: 'ใช้โค้ดเติมเงิน' },
        'admin.deposit.redeem.placeholder': { en: 'Enter 6-char code', th: 'กรอกโค้ด 6 ตัว' },
        'admin.deposit.redeem.btn': { en: 'Redeem', th: 'เติมเงิน' },
        'admin.deposit.slip.title': { en: 'Attach Slip', th: 'แนบสลิป' },
        'admin.deposit.slip.mobile_banking': { en: 'Mobile Banking', th: 'โมบายแบงก์กิ้ง' },
        'admin.deposit.slip.account_name': { en: 'Account Name', th: 'ชื่อบัญชี' },
        'admin.deposit.slip.bank': { en: 'Bank', th: 'ธนาคาร' },
        'admin.deposit.slip.account_number': { en: 'Account Number', th: 'เลขบัญชี' },
        'admin.deposit.slip.drag_drop': { en: 'Drag & drop to upload', th: 'ลาก & วาง เพื่ออัปโหลด' },
        'admin.deposit.slip.or': { en: 'or', th: 'หรือ' },
        'admin.deposit.slip.upload_btn': { en: 'Upload file', th: 'อัปโหลดไฟล์' },
        'admin.deposit.slip.warning': { en: 'Please transfer via banking app only.', th: 'กรุณาโอนผ่านแอปธนาคารเท่านั้น' },
        'admin.deposit.slip.verifying': { en: 'Verifying slip...', th: 'กำลังตรวจสอบสลิป...' },
        'admin.deposit.slip.success': { en: 'Deposit successful! +{amount} THB', th: 'เติมเงินสำเร็จ! +{amount} บาท' },
        'admin.deposit.binance.title': { en: 'USDT Deposit (Binance)', th: 'เติมเงิน USDT (Binance)' },
        'admin.deposit.binance.important': { en: 'Important!', th: 'สำคัญมาก!' },
        'admin.deposit.binance.network_warning': { en: 'Send via TRC20 only', th: 'กรุณาโอนผ่านเครือข่าย TRC20 เท่านั้น' },
        'admin.deposit.binance.loss_warning': { en: 'Wrong network will be lost', th: 'โอนผิดเครือข่าย ยอดเงินจะไม่เข้าระบบและไม่สามารถกู้คืนได้' },
        'admin.deposit.binance.usdt_only': { en: 'Only USDT (Tether) supported', th: 'รองรับเฉพาะ USDT (Tether)' },
        'admin.deposit.binance.network_info': { en: 'Network: TRC20 (Tron)', th: 'เครือข่าย: TRC20 (Tron)' },
        'admin.deposit.binance.auto_convert': { en: 'Auto-convert to THB', th: 'แปลงเป็น THB อัตโนมัติ' },
        'admin.deposit.binance.wait_info': { en: 'Wait 1-5 minutes then enter TxID', th: 'รอ 1-5 นาทีหลังโอนแล้วค่อยกรอก TxID' },
        'admin.deposit.binance.txid_placeholder': { en: 'Enter TxID', th: 'กรอก TxID' },
        'admin.deposit.binance.verify_btn': { en: 'Verify', th: 'ตรวจสอบ' },
        'admin.deposit.binance.verifying': { en: 'Verifying...', th: 'กำลังตรวจสอบ...' },
        'admin.deposit.binance.success_msg': { en: 'Deposit successful! {usdt} USDT = {thb} THB', th: 'เติมเงินสำเร็จ! {usdt} USDT = {thb} บาท' },
        'admin.deposit.angpao.title': { en: 'TrueMoney Angpao', th: 'ซองอั่งเปา TrueMoney' },
        'admin.deposit.angpao.placeholder': { en: 'Paste Angpao link', th: 'วางลิงก์ซองอั่งเปา' },
        'admin.deposit.angpao.btn': { en: 'Top up', th: 'เติมเงิน' },
        'admin.deposit.angpao.info': { en: 'Create voucher for 1 recipient only.', th: 'สร้างซองอั่งเปาแบบผู้รับ 1 คนเท่านั้น' },

        // ==========================================
        // Admin Products (extras)
        // ==========================================
        'admin.products.empty': { en: 'No products found. Add your first product!', th: 'ยังไม่มีสินค้า กรุณาเพิ่มสินค้า' },
        'admin.products.added_on': { en: 'Added:', th: 'เพิ่มเมื่อ:' },
        'admin.products.add_variant_btn': { en: 'Add Variant', th: 'เพิ่มรูปแบบ' },
        'admin.products.add_keys': { en: 'Keys', th: 'คีย์' },
        'admin.products.add_modal_title': { en: 'Add New Product', th: 'เพิ่มสินค้าใหม่' },
        'admin.products.edit_modal_title': { en: 'Edit Product', th: 'แก้ไขสินค้า' },
        'admin.products.form_name': { en: 'Product Name', th: 'ชื่อสินค้า' },
        'admin.products.form_category': { en: 'Categories (1 - 4)', th: 'หมวดหมู่ (1 - 4)' },
        'admin.products.category_placeholder': { en: 'Category {number}', th: 'หมวดหมู่ {number}' },
        'admin.products.category_hint': { en: 'Fill 1 to 4 categories. Product will appear in each category list.', th: 'กรอก 1-4 หมวดหมู่ สินค้าจะแสดงในทุกหมวด' },
        'admin.products.form_image': { en: 'Upload Image', th: 'อัปโหลดรูปภาพ' },
        'admin.products.image_edit_hint': { en: 'Leave empty to keep current image.', th: 'เว้นว่างเพื่อใช้รูปเดิม' },
        'admin.products.image_add_hint': { en: 'Select an image file for the product.', th: 'เลือกไฟล์รูปภาพของสินค้า' },
        'admin.products.form_dl_url': { en: 'Download Link (URL)', th: 'ลิงก์ดาวน์โหลด (URL)' },
        'admin.products.download_url_hint': { en: 'This link is specific to this product only.', th: 'ลิงก์นี้เฉพาะสินค้านี้เท่านั้น' },
        'admin.products.form_desc': { en: 'Description', th: 'รายละเอียด' },
        'admin.products.initial_variants': { en: 'Initial Variants', th: 'รูปแบบเริ่มต้น' },
        'admin.products.keys_hint': { en: 'Paste your keys here, one per line', th: 'วางคีย์ทีละบรรทัด' },
        'admin.products.form_save': { en: 'Update Product', th: 'อัปเดตสินค้า' },
        'admin.products.duration_label': { en: 'Duration', th: 'ระยะเวลา' },
        'admin.products.duration_placeholder': { en: 'e.g., 1 Month, 6 Months, 1 Year', th: 'เช่น 1 เดือน, 6 เดือน, 1 ปี' },
        'admin.products.price_user': { en: 'User Price', th: 'ราคาผู้ใช้' },
        'admin.products.price_reseller': { en: 'Reseller Price', th: 'ราคารีเซลเลอร์' },
        'admin.products.cost_label': { en: 'Cost (Modal)', th: 'ต้นทุน' },
        'admin.products.modal.product_label': { en: 'Product:', th: 'สินค้า:' },
        'admin.products.modal_edit_variant_title': { en: 'Edit Variant', th: 'แก้ไขรูปแบบ' },
        'admin.products.update_variant_btn': { en: 'Update Variant', th: 'อัปเดตรูปแบบ' },
        'admin.products.variant.add_btn': { en: 'Add Variant', th: 'เพิ่มรูปแบบ' },
        'admin.products.variant.add_keys': { en: 'Add Keys to Variant', th: 'เพิ่มคีย์ให้รูปแบบ' },
        'admin.products.variant.total_keys': { en: 'Total Keys:', th: 'คีย์ทั้งหมด:' },
        'admin.products.variant.available_keys': { en: 'Available:', th: 'พร้อมใช้งาน:' },
        'admin.products.no_variants': { en: 'No variants added yet', th: 'ยังไม่มีรูปแบบ' },
        'admin.products.confirm_delete_product': { en: 'Delete this product?', th: 'ลบสินค้านี้หรือไม่?' },
        'admin.products.confirm_delete_variant': { en: 'Delete this variant?', th: 'ลบรูปแบบนี้หรือไม่?' },

        // ==========================================
        // Admin Profit extras
        // ==========================================
        'admin.profit.heading': { en: 'Profit Analysis', th: 'วิเคราะห์กำไร' },
        'admin.profit.start': { en: 'Start', th: 'เริ่ม' },
        'admin.profit.end': { en: 'End', th: 'สิ้นสุด' },
        'admin.profit.apply': { en: 'Apply', th: 'นำไปใช้' },
        'admin.profit.cost_modal': { en: 'Cost (Modal)', th: 'ต้นทุน' },
        'admin.profit.by_product': { en: 'By Product', th: 'แยกตามสินค้า' },
        'admin.profit.top_500': { en: 'Top 500 transactions', th: 'ธุรกรรมล่าสุด 500 รายการ' },
        'admin.profit.table.sold': { en: 'Sold', th: 'ขาย' },
        'admin.profit.table.sell': { en: 'Sell', th: 'ราคาขาย' },
        'admin.profit.empty_range': { en: 'No data in this date range.', th: 'ไม่มีข้อมูลในช่วงวันที่เลือก' },
        'admin.profit.recent_purchases': { en: 'Recent Purchases (detail)', th: 'รายการซื้อล่าสุด (รายละเอียด)' },
        'admin.profit.includes_reseller': { en: 'Includes reseller & user purchases', th: 'รวมรายการของผู้ใช้และรีเซลเลอร์' },
        'admin.profit.empty_purchases': { en: 'No purchases found.', th: 'ไม่พบรายการซื้อ' },
        'admin.profit.help_text': { en: 'Profit = Sell - Cost per key.', th: 'กำไร = ราคาขาย - ต้นทุนต่อคีย์' },

        // ==========================================
        // Admin Resellers extras
        // ==========================================
        'admin.resellers.title': { en: 'Manage Resellers', th: 'จัดการรีเซลเลอร์' },
        'admin.resellers.heading': { en: 'Resellers', th: 'รีเซลเลอร์' },
        'admin.resellers.add_btn': { en: 'Add Reseller', th: 'เพิ่มรีเซลเลอร์' },
        'admin.resellers.empty': { en: 'No resellers found', th: 'ไม่พบรีเซลเลอร์' },
        'admin.resellers.confirm_ban': { en: 'Ban {username}?', th: 'แบน {username} ?' },
        'admin.resellers.confirm_unban': { en: 'Unban {username}?', th: 'ยกเลิกแบน {username} ?' },
        'admin.resellers.confirm_delete': { en: 'Delete {username}?', th: 'ลบ {username} ?' },
        'admin.resellers.modal.add_title': { en: 'Add Reseller', th: 'เพิ่มรีเซลเลอร์' },
        'admin.resellers.modal.add_balance': { en: 'Add Balance', th: 'เพิ่มยอดเงิน' },
        'admin.resellers.modal.deduct_balance': { en: 'Deduct Balance', th: 'หักยอดเงิน' },
        'admin.resellers.modal.operation': { en: 'Operation', th: 'การทำรายการ' },
        'admin.resellers.modal.reseller_label': { en: 'Reseller', th: 'รีเซลเลอร์' },
        'admin.resellers.modal.update_balance': { en: 'Update Balance', th: 'อัปเดตยอดเงิน' },

        // ==========================================
        // Admin Settings extras (placeholders/hints)
        // ==========================================
        'admin.settings.status': { en: 'Status', th: 'สถานะ' },
        'admin.settings.inactive': { en: 'Inactive', th: 'ปิดใช้งาน' },
        'admin.settings.account_number_placeholder': { en: 'Enter account number', th: 'กรอกเลขบัญชี' },
        'admin.settings.receiver_name_placeholder': { en: 'Enter receiver name', th: 'กรอกชื่อผู้รับเงิน' },
        'admin.settings.username_placeholder': { en: 'Enter username', th: 'กรอกชื่อผู้ใช้' },
        'admin.settings.email_placeholder': { en: 'Enter email', th: 'กรอกอีเมล' },
        'admin.settings.password_placeholder': { en: 'Enter password', th: 'กรอกรหัสผ่าน' },
        'admin.settings.confirm_password_placeholder': { en: 'Confirm password', th: 'ยืนยันรหัสผ่าน' },
        'admin.settings.current_password_hint': { en: 'Required to update account settings.', th: 'ต้องกรอกเพื่อบันทึกการเปลี่ยนแปลงบัญชี' },
        'admin.settings.new_password_hint': { en: 'Leave blank to keep current password.', th: 'เว้นว่างเพื่อใช้รหัสผ่านเดิม' },
        'admin.settings.announcement_default': { en: 'Welcome to the shop!', th: 'ยินดีต้อนรับเข้าสู่ร้านค้า!' },
        'admin.settings.announcement_placeholder': { en: 'Type announcement...', th: 'พิมพ์ข้อความประกาศ...' },
        'admin.settings.announcement_tip': { en: 'Tip: keep it short', th: 'คำแนะนำ: เขียนให้สั้นกระชับ' },
        'admin.settings.announcement_scroll_tip': { en: 'Scrolling marquee supported', th: 'รองรับการเลื่อนข้อความ' },
        'admin.settings.announcement_clear_confirm': { en: 'Are you sure you want to clear the announcement text?', th: 'คุณแน่ใจหรือไม่ว่าต้องการล้างข้อความประกาศ?' },
        'admin.settings.characters': { en: 'characters', th: 'ตัวอักษร' },
        'admin.settings.wallet_hint': { en: 'Enter wallet number', th: 'กรอกหมายเลขวอลเล็ท' },
        'admin.settings.enable_truemoney': { en: 'Enable TrueMoney', th: 'เปิดใช้งาน TrueMoney' },
        'admin.settings.easyslip_enabled': { en: 'Enable EasySlip', th: 'เปิดใช้งาน EasySlip' },
        'admin.settings.easyslip_info': { en: 'EasySlip verifies bank slips', th: 'EasySlip ใช้ตรวจสอบสลิปธนาคาร' },
        'admin.settings.easyslip_account': { en: 'Receiver Account', th: 'บัญชีผู้รับเงิน' },
        'admin.settings.easyslip_account_hint': { en: 'Used to validate transfers', th: 'ใช้ตรวจสอบปลายทางโอน' },
        'admin.settings.easyslip_phone_hint': { en: 'Optional, for proxy validation', th: 'ไม่บังคับ ใช้ตรวจสอบแบบพร็อกซี' },
        'admin.settings.easyslip_phone_placeholder': { en: 'Receiver phone number', th: 'กรอกเบอร์โทรผู้รับเงิน' },
        'admin.settings.binance_enabled': { en: 'Enable Binance', th: 'เปิดใช้งาน Binance' },
        'admin.settings.binance_info': { en: 'Auto-credit USDT deposits', th: 'เติมเงิน USDT อัตโนมัติ' },
        'admin.settings.binance_wallet_hint': { en: 'TRC20 address only', th: 'ใช้ที่อยู่ TRC20 เท่านั้น' },
        'admin.settings.binance_secret_hint': { en: 'Keep secret key safe', th: 'เก็บ Secret Key ให้ปลอดภัย' },
        'admin.settings.save_truemoney': { en: 'Save TrueMoney', th: 'บันทึก TrueMoney' },
        'admin.settings.save_easyslip': { en: 'Save EasySlip', th: 'บันทึก EasySlip' },
        'admin.settings.account_username': { en: 'Username', th: 'ชื่อผู้ใช้' },
        'admin.settings.account_email': { en: 'Email', th: 'อีเมล' },
        'admin.settings.easyslip_phone': { en: 'Receiver Phone', th: 'เบอร์โทรผู้รับเงิน' },

        // ==========================================
        // Admin Transactions extras
        // ==========================================
        'admin.transactions.heading': { en: 'Transactions', th: 'ธุรกรรม' },
        'admin.transactions.search_label': { en: 'Search', th: 'ค้นหา' },
        'admin.transactions.search_placeholder': { en: 'Search by user, product, tx...', th: 'ค้นหาด้วยผู้ใช้/สินค้า/รายการ...' },
        'admin.transactions.filter_user': { en: 'Filter user', th: 'กรองผู้ใช้' },
        'admin.transactions.all_users': { en: 'All users', th: 'ผู้ใช้ทั้งหมด' },
        'admin.transactions.empty': { en: 'No transactions found', th: 'ไม่พบธุรกรรม' },
        'admin.transactions.latest': { en: 'Latest', th: 'ล่าสุด' },
        'admin.transactions.more_codes': { en: 'more', th: 'เพิ่มเติม' },
        'admin.transactions.modal.close': { en: 'Close', th: 'ปิด' },
        'admin.deposit.error.used_slip': { en: 'This slip has already been used!', th: 'สลิปนี้ถูกใช้งานไปแล้ว ห้ามใช้ซ้ำ!' },
        'admin.deposit.error.used_txid': { en: 'This TxID has already been claimed!', th: 'TxID นี้ถูกใช้งานไปแล้ว ห้ามใช้ซ้ำ!' },

        // ==========================================
        // Admin Out of Stock
        // ==========================================
        'admin.out_of_stock.title': { en: 'Out of Stock Variants', th: 'รายการสินค้าที่หมด' },

        // ==========================================
        // Admin Users extras
        // ==========================================
        'admin.users.empty': { en: 'No users found', th: 'ไม่พบผู้ใช้' },
        'admin.users.confirm_ban': { en: 'Ban {username}?', th: 'แบน {username} ?' },
        'admin.users.confirm_unban': { en: 'Unban {username}?', th: 'ยกเลิกแบน {username} ?' },
        'admin.users.confirm_delete': { en: 'Delete {username}?', th: 'ลบ {username} ?' },
        'admin.users.ban_title': { en: 'Ban user', th: 'แบนผู้ใช้' },
        'admin.users.unban_title': { en: 'Unban user', th: 'ยกเลิกแบนผู้ใช้' },
        'admin.users.delete_title': { en: 'Delete user', th: 'ลบผู้ใช้' },

        // Admin deposit misc missing key
        'admin.deposit.binance.copy': { en: 'Copy', th: 'คัดลอก' },
        'admin.settings.store_title_color_hint': { en: 'Pick a color (hex)', th: 'เลือกสี (hex)' },
        'admin.settings.store_title_style_hint': { en: 'Use Tailwind font classes', th: 'ใช้คลาสตัวอักษรของ Tailwind' },

        // ==========================================
        // Buy (user/reseller)
        // ==========================================
        'buy.title': { en: 'Buy Keys', th: 'ซื้อคีย์' },
        'buy.search_placeholder': { en: 'Search products...', th: 'ค้นหาสินค้า...' },
        'buy.categories': { en: 'Categories', th: 'หมวดหมู่' },
        'buy.category_all': { en: 'All', th: 'ทั้งหมด' },
        'buy.no_products_found': { en: 'No products found', th: 'ไม่พบสินค้า' },
        'buy.clear_filters_btn': { en: 'Clear filters', th: 'ล้างตัวกรอง' },
        'buy.sold_out': { en: 'Sold Out', th: 'สินค้าหมด' },
        'buy.date': { en: 'Date:', th: 'วันที่:' },

        // ==========================================
        // Dashboard (user/reseller)
        // ==========================================
        'dashboard.search.button': { en: 'Search', th: 'ค้นหา' },
        'dashboard.categories': { en: 'Categories:', th: 'หมวดหมู่:' },
        'dashboard.clear_filter': { en: 'Clear', th: 'ล้าง' },
        'dashboard.view_all_keys': { en: 'View All Keys', th: 'ดูคีย์ทั้งหมด' },
        'dashboard.insufficient_balance': { en: 'Insufficient balance!', th: 'ยอดเงินไม่เพียงพอ!' },
        'dashboard.table.product': { en: 'Product', th: 'สินค้า' },
        'dashboard.table.duration': { en: 'Duration', th: 'ระยะเวลา' },
        'dashboard.table.key': { en: 'Key Code', th: 'รหัสคีย์' },
        'dashboard.table.paid': { en: 'Paid', th: 'ราคาที่จ่าย' },
        'dashboard.table.date': { en: 'Date', th: 'วันที่' },

        // ==========================================
        // Deposit (user/reseller generic keys)
        // ==========================================
        'deposit.title': { en: 'Deposit', th: 'เติมเงิน' },
        'deposit.my_balance': { en: 'My Balance:', th: 'ยอดเงินของฉัน:' },
        'deposit.current_balance': { en: 'Current Balance', th: 'ยอดเงินปัจจุบัน' },
        'deposit.add_balance': { en: 'Add Balance', th: 'เติมเงิน' },
        'deposit.instant_title': { en: 'Instant Deposit', th: 'เติมเงินทันที' },
        'deposit.instant_desc': { en: 'Add funds instantly with zero manual approval!', th: 'เติมเงินทันทีโดยไม่ต้องรออนุมัติ' },
        'deposit.redeem.title': { en: 'Redeem Code', th: 'ใช้โค้ดเติมเงิน' },
        'deposit.redeem.placeholder': { en: 'Enter 6-char code', th: 'กรอกโค้ด 6 ตัว' },
        'deposit.redeem.btn': { en: 'Redeem', th: 'เติมเงิน' },
        'deposit.redeem.warning': { en: 'Warning: 10 wrong attempts will suspend your account.', th: 'คำเตือน: กรอกผิด 10 ครั้ง บัญชีจะถูกระงับ' },
        'deposit.redeem_code': { en: 'Redeem Code', th: 'ใช้โค้ดเติมเงิน' },
        'deposit.redeem_btn': { en: 'Redeem', th: 'เติมเงิน' },
        'deposit.redeem_placeholder': { en: 'Enter code', th: 'กรอกโค้ด' },
        'deposit.redeem_warning': { en: 'Warning: 10 wrong attempts will suspend your account.', th: 'คำเตือน: กรอกผิด 10 ครั้ง บัญชีจะถูกระงับ' },
        'deposit.slip.title': { en: 'Upload Slip', th: 'แนบสลิป' },
        'deposit.slip.subtitle': { en: 'Mobile Banking', th: 'โมบายแบงก์กิ้ง' },
        'deposit.slip.banking': { en: 'Mobile Banking', th: 'โมบายแบงก์กิ้ง' },
        'deposit.slip.fee_free': { en: '0% fee', th: 'ไม่มีค่าธรรมเนียม 0%' },
        'deposit.slip.no_fee': { en: '0% fee', th: 'ไม่มีค่าธรรมเนียม 0%' },
        'deposit.slip.account_name': { en: 'Account Name', th: 'ชื่อบัญชี' },
        'deposit.slip.bank': { en: 'Bank', th: 'ธนาคาร' },
        'deposit.slip.account_number': { en: 'Account Number', th: 'เลขบัญชี' },
        'deposit.slip.account_name_label': { en: 'Account Name', th: 'ชื่อบัญชี' },
        'deposit.slip.account_name_value': { en: 'Account Name', th: 'ชื่อบัญชี' },
        'deposit.slip.bank_label': { en: 'Bank', th: 'ธนาคาร' },
        'deposit.slip.bank_value': { en: 'Bank', th: 'ธนาคาร' },
        'deposit.slip.account_number_label': { en: 'Account Number', th: 'เลขบัญชี' },
        'deposit.slip.drag_drop': { en: 'Drag & drop to upload', th: 'ลาก & วาง เพื่ออัปโหลด' },
        'deposit.slip.upload_hint': { en: 'Drag & drop to upload', th: 'ลาก & วาง เพื่ออัปโหลด' },
        'deposit.slip.or': { en: 'or', th: 'หรือ' },
        'deposit.slip.upload_btn': { en: 'Upload file', th: 'อัปโหลดไฟล์' },
        'deposit.slip.warning': { en: 'Please transfer via banking app only.', th: 'กรุณาโอนผ่านแอปธนาคารเท่านั้น' },
        'deposit.binance.title': { en: 'USDT (Binance)', th: 'เติมเงินผ่าน USDT (Binance)' },
        'deposit.binance.subtitle': { en: 'TRC20 Network', th: 'เครือข่าย TRC20' },
        'deposit.binance.important': { en: 'Important!', th: 'สำคัญมาก!' },
        'deposit.binance.network_warning': { en: 'Send USDT via TRC20 only', th: 'กรุณาโอน USDT ผ่านเครือข่าย TRC20 เท่านั้น' },
        'deposit.binance.recover_warning': { en: 'Wrong network cannot be recovered', th: 'โอนผิดเครือข่าย ยอดเงินจะไม่เข้าระบบและไม่สามารถกู้คืนได้' },
        'deposit.binance.loss_warning': { en: 'Wrong network cannot be recovered', th: 'โอนผิดเครือข่าย ยอดเงินจะไม่เข้าระบบและไม่สามารถกู้คืนได้' },
        'deposit.binance.wallet_label': { en: 'Wallet Address (TRC20)', th: 'ที่อยู่กระเป๋า (TRC20)' },
        'deposit.binance.support_usdt': { en: 'Only USDT supported', th: 'รองรับเฉพาะ USDT เท่านั้น' },
        'deposit.binance.support_network': { en: 'Network: TRC20 (Tron)', th: 'เครือข่าย: TRC20 (Tron)' },
        'deposit.binance.support_auto_convert': { en: 'Auto convert to THB', th: 'แปลงเป็น THB อัตโนมัติ' },
        'deposit.binance.support_wait': { en: 'Wait 1-5 minutes then enter TxID', th: 'รอ 1-5 นาทีหลังโอนแล้วค่อยกรอก TxID' },
        'deposit.binance.txid_placeholder': { en: 'Paste TxID here', th: 'วาง TxID ที่นี่' },
        'deposit.binance.verify_btn': { en: 'Verify', th: 'ตรวจสอบ' },
        'deposit.binance.button': { en: 'Verify', th: 'ตรวจสอบ' },
        'deposit.binance.placeholder': { en: 'Paste TxID here', th: 'วาง TxID ที่นี่' },
        'deposit.amount': { en: 'Amount', th: 'จำนวนเงิน' },
        'deposit.amount_placeholder': { en: 'Enter amount', th: 'กรอกจำนวนเงิน' },
        'deposit.mobile': { en: 'Mobile Number', th: 'เบอร์มือถือ' },
        'deposit.mobile_placeholder': { en: 'e.g., 082xxxxxxx', th: 'เช่น 082xxxxxxx' },
        'deposit.pay_btn': { en: 'Pay Instantly', th: 'ชำระทันที' },
        'deposit.angpao.title': { en: 'TrueMoney Angpao', th: 'ซองอั่งเปา TrueMoney' },
        'deposit.angpao.placeholder': { en: 'Paste voucher link', th: 'วางลิงก์ซองอั่งเปา' },
        'deposit.angpao.btn': { en: 'Top up', th: 'เติมเงิน' },
        'deposit.angpao.button': { en: 'Top up', th: 'เติมเงิน' },
        'deposit.angpao.warning': { en: 'Voucher must be for 1 recipient only.', th: 'ต้องเป็นซองอั่งเปาแบบผู้รับ 1 คนเท่านั้น' },
        'deposit.angpao.verifying': { en: 'Verifying...', th: 'กำลังตรวจสอบ...' },
        'deposit.error.used_slip': { en: 'This slip has already been used!', th: 'สลิปนี้ถูกใช้งานไปแล้ว ห้ามใช้ซ้ำ!' },
        'deposit.error.used_txid': { en: 'This TxID has already been claimed!', th: 'TxID นี้ถูกใช้งานไปแล้ว ห้ามใช้ซ้ำ!' },
        'deposit.error.invalid_account': { en: 'Invalid receiver account!', th: 'บัญชีปลายทางไม่ถูกต้อง!' },
        'deposit.error.invalid_txid': { en: 'Invalid TxID or not found!', th: 'TxID ไม่ถูกต้องหรือไม่พบข้อมูล!' },
        'deposit.error.server': { en: 'System error, please try later.', th: 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่ภายหลัง' },
        'deposit.error.failed': { en: 'Deposit failed. Please try again.', th: 'การเติมเงินล้มเหลว กรุณาลองใหม่' },
        'deposit.status.success': { en: 'Deposit successful', th: 'เติมเงินสำเร็จ' },
        'deposit.select_method': { en: 'Please select your deposit method', th: 'โปรดเลือกช่องทางการเติมเงินของคุณ' },
        'deposit.angpao.fee': { en: 'Fee 2.9% up to 20฿', th: 'ค่าธรรมเนียม 2.9% สูงสุด 20฿' },
        'deposit.angpao.label': { en: 'Angpao Link', th: 'ลิงก์ซองอั่งเปา' },
        'deposit.redeem.instant': { en: 'Instant Reward', th: 'แลกรางวัลทันที' },
        'deposit.redeem.label': { en: 'Redeem Code', th: 'รหัสเติมเงิน' },
        'deposit.slip.warning_mobile': { en: 'Please transfer via bank app only. System does not support TrueMoney Transfer.', th: 'กรุณาโอนผ่านแอปธนาคารเท่านั้น ระบบไม่รองรับการโอนด้วยทรูมันนี่' },

        // ==========================================
        // History (user/reseller)
        // ==========================================
        'history.copy_success': { en: 'Copied!', th: 'คัดลอกแล้ว!' },
        'history.detail_title': { en: 'Purchase Detail', th: 'รายละเอียดการซื้อ' },
        'history.latest': { en: 'Latest', th: 'ล่าสุด' },
        'history.empty': { en: 'No history yet', th: 'ยังไม่มีประวัติ' },
        'history.more_codes': { en: 'more', th: 'เพิ่มเติม' },

        // Misc common/table keys (used by some pages)
        'common.table.actions': { en: 'Actions', th: 'การดำเนินการ' },
        'common.product': { en: 'Product', th: 'สินค้า' },
        'common.duration': { en: 'Duration', th: 'ระยะเวลา' },
        'common.price_paid': { en: 'Price Paid', th: 'ราคาที่จ่าย' },
        'common.purchase_date': { en: 'Purchase Date', th: 'วันที่ซื้อ' },

        // Register (used in account forms)
        'register.confirm_password.placeholder': { en: 'Re-enter password', th: 'กรอกรหัสผ่านอีกครั้ง' },

        // Users generic
        // Binance Gift Card
        'common.previous': { en: 'Previous', th: 'ก่อนหน้า' },
        'common.next': { en: 'Next', th: 'ถัดไป' },
        'nav.binance_giftcards': { en: 'Binance Gift Cards', th: 'ของขวัญ Binance' },
        'giftcard.title': { en: 'Binance Gift Card', th: 'ของขวัญ Binance' },
        'giftcard.usdt_only': { en: 'Only USDT Binance Gift Cards are credited automatically.', th: 'รองรับการเติมยอดอัตโนมัติเฉพาะ Binance Gift Card ที่เป็น USDT' },
        'giftcard.code_only_warning': { en: 'A redemption code is consumed immediately when Binance accepts it. The token and value are confirmed from the redemption result. Non-USDT or policy exceptions are held for administrator review.', th: 'เมื่อ Binance รับรหัส รหัสจะถูกใช้ทันที ระบบจะตรวจเหรียญและมูลค่าจากผลการแลก หากไม่ใช่ USDT หรือไม่ผ่านเงื่อนไข รายการจะถูกพักให้ผู้ดูแลตรวจสอบ' },
        'giftcard.code_label': { en: '16-character redemption code', th: 'รหัสรับของขวัญ 16 ตัว' },
        'giftcard.code_hint': { en: 'Letters and numbers only. Spaces and hyphens are removed automatically.', th: 'ใช้ตัวอักษรและตัวเลขเท่านั้น ระบบจะตัดช่องว่างและขีดออกให้อัตโนมัติ' },
        'giftcard.toggle_code': { en: 'Show or hide code', th: 'แสดงหรือซ่อนรหัส' },
        'giftcard.redeem_button': { en: 'Redeem Gift Card', th: 'รับของขวัญ' },
        'giftcard.processing': { en: 'Redeeming securely. Do not submit the code again.', th: 'กำลังรับของขวัญอย่างปลอดภัย ห้ามส่งรหัสซ้ำ' },
        'giftcard.secret_warning': { en: 'Keep this code secret. The full code is removed from the form after submission and is not shown in transaction history.', th: 'เก็บรหัสนี้เป็นความลับ รหัสเต็มจะถูกลบจากแบบฟอร์มหลังส่งและไม่แสดงในประวัติธุรกรรม' },
        'giftcard.success': { en: 'Gift Card credited successfully', th: 'รับของขวัญและเติมยอดสำเร็จ' },
        'giftcard.support_code': { en: 'Support code', th: 'รหัสตรวจสอบ' },
        'giftcard.error.disabled': { en: 'Binance Gift Card redemption is currently unavailable.', th: 'ระบบรับของขวัญ Binance ยังไม่เปิดใช้งาน' },
        'giftcard.error.unavailable': { en: 'The Gift Card service is temporarily unavailable. Please try again later.', th: 'ระบบรับของขวัญไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง' },
        'giftcard.error.format': { en: 'Enter a valid 16-character redemption code.', th: 'กรุณากรอกรหัสรับของขวัญ 16 ตัวให้ถูกต้อง' },
        'giftcard.error.role_disabled': { en: 'Gift Card redemption is not available for this account type.', th: 'บัญชีประเภทนี้ยังไม่สามารถใช้ระบบรับของขวัญได้' },
        'giftcard.error.account_age': { en: 'This account is not yet eligible to redeem a Gift Card.', th: 'บัญชีนี้ยังไม่ผ่านระยะเวลาที่กำหนดสำหรับรับของขวัญ' },
        'giftcard.error.provider_limit': { en: 'Gift Card verification is temporarily paused for safety. Please contact an administrator.', th: 'ระบบตรวจรหัสถูกพักชั่วคราวเพื่อความปลอดภัย กรุณาติดต่อผู้ดูแล' },
        'giftcard.error.user_limit': { en: 'You have reached today\'s Gift Card attempt limit.', th: 'คุณใช้จำนวนครั้งในการลองรหัสของวันนี้ครบแล้ว' },
        'giftcard.error.wait': { en: 'Please wait before trying another Gift Card code.', th: 'กรุณารอก่อนลองรหัสของขวัญอีกครั้ง' },
        'giftcard.error.used_or_invalid': { en: 'The card code is invalid or has already been used.', th: 'รหัสบัตรไม่ถูกต้อง หรือ บัตรถูกใช้งานไปแล้ว' },
        'giftcard.error.review': { en: 'The code may already have been accepted. Do not submit it again. An administrator must review this transaction.', th: 'รหัสอาจถูก Binance รับแล้ว ห้ามส่งซ้ำ รายการนี้ต้องให้ผู้ดูแลตรวจสอบ' },
        'giftcard.error.not_usdt': { en: 'The Gift Card was redeemed but is not USDT. It was not credited to the website and requires administrator review.', th: 'รับของขวัญสำเร็จแต่เหรียญไม่ใช่ USDT จึงยังไม่เติมยอดเว็บไซต์และต้องให้ผู้ดูแลตรวจสอบ' },
        'giftcard.error.amount_review': { en: 'The Gift Card was redeemed but its amount requires administrator review.', th: 'รับของขวัญสำเร็จแต่มูลค่าต้องให้ผู้ดูแลตรวจสอบก่อนเติมยอด' },
        'giftcard.error.currency': { en: 'The website currency configuration does not support automatic Gift Card credit.', th: 'การตั้งค่าสกุลเงินของเว็บไซต์ไม่รองรับการเติมยอด Gift Card อัตโนมัติ' },
        'giftcard.error.already_credited': { en: 'This Gift Card has already been credited.', th: 'ของขวัญนี้ถูกเติมยอดแล้ว' },
        'giftcard.error.already_submitted': { en: 'This Gift Card code has already been submitted.', th: 'รหัสของขวัญนี้ถูกส่งเข้าระบบแล้ว' },
        'giftcard.error.processing': { en: 'This Gift Card is still being processed. Do not submit it again.', th: 'ของขวัญนี้กำลังดำเนินการอยู่ ห้ามส่งรหัสซ้ำ' },
        'admin.settings.giftcard_title': { en: 'Binance Gift Card', th: 'Binance Gift Card' },
        'admin.settings.giftcard_subtitle': { en: 'Redeem 16-character Binance Gift Card codes while keeping TRC20 deposits unchanged.', th: 'รับรหัสของขวัญ Binance 16 ตัว โดยคงระบบฝาก TRC20 เดิมไว้' },
        'admin.settings.giftcard_audit': { en: 'Redemption audit', th: 'ตรวจสอบรายการรับของขวัญ' },
        'admin.settings.giftcard_enabled': { en: 'Enable Binance Gift Card', th: 'เปิดระบบ Binance Gift Card' },
        'admin.settings.giftcard_users': { en: 'Allow users', th: 'อนุญาตผู้ใช้งาน' },
        'admin.settings.giftcard_resellers': { en: 'Allow resellers', th: 'อนุญาตตัวแทน' },
        'admin.settings.giftcard_credentials_mode': { en: 'API credential mode', th: 'รูปแบบ API Key' },
        'admin.settings.giftcard_credentials_shared': { en: 'Use existing TRC20 API credentials', th: 'ใช้ API Key เดียวกับ TRC20' },
        'admin.settings.giftcard_credentials_separate': { en: 'Use separate Gift Card API credentials', th: 'ใช้ API Key แยกสำหรับ Gift Card' },
        'admin.settings.giftcard_credentials_hint': { en: 'A separate restricted API key is recommended. The saved secret is never displayed.', th: 'แนะนำให้ใช้ API Key แยกและจำกัดสิทธิ์ Secret ที่บันทึกไว้จะไม่ถูกแสดง' },
        'admin.settings.giftcard_api_key': { en: 'Gift Card API Key', th: 'Gift Card API Key' },
        'admin.settings.giftcard_secret_key': { en: 'Gift Card Secret Key', th: 'Gift Card Secret Key' },
        'admin.settings.giftcard_secret_notice': { en: 'Leave blank to keep the currently saved value. Secrets are never shown back in the form.', th: 'เว้นว่างเพื่อใช้ค่าเดิม ระบบจะไม่แสดง Secret ที่บันทึกไว้กลับมาในแบบฟอร์ม' },
        'admin.settings.giftcard_token': { en: 'Accepted token', th: 'เหรียญที่รองรับ' },
        'admin.settings.giftcard_token_hint': { en: 'Automatic credit is restricted to USDT.', th: 'เติมยอดอัตโนมัติเฉพาะ USDT' },
        'admin.settings.giftcard_min': { en: 'Minimum per card (USDT)', th: 'ขั้นต่ำต่อใบ (USDT)' },
        'admin.settings.giftcard_max': { en: 'Maximum per card (USDT)', th: 'สูงสุดต่อใบ (USDT)' },
        'admin.settings.giftcard_daily': { en: 'Maximum per account per day (USDT)', th: 'สูงสุดต่อบัญชีต่อวัน (USDT)' },
        'admin.settings.giftcard_credit_percent': { en: 'Website credit percentage', th: 'เปอร์เซ็นต์ยอดที่เติมเข้าเว็บไซต์' },
        'admin.settings.giftcard_user_attempts': { en: 'Attempts per account per day', th: 'จำนวนครั้งต่อบัญชีต่อวัน' },
        'admin.settings.giftcard_global_invalid': { en: 'Global invalid-code stop limit', th: 'จำนวนรหัสผิดรวมก่อนพักระบบ' },
        'admin.settings.giftcard_account_age': { en: 'Minimum account age (days)', th: 'อายุบัญชีขั้นต่ำ (วัน)' },
        'admin.settings.giftcard_count_ranking': { en: 'Count toward deposit rankings', th: 'นับในอันดับยอดเติมเงิน' },
        'admin.settings.giftcard_count_ranking_hint': { en: 'Records the qualifying THB value in the ranking ledger.', th: 'บันทึกมูลค่าเงินบาทที่เข้าเงื่อนไขในระบบจัดอันดับ' },
        'admin.settings.giftcard_rank_bonus': { en: 'Apply monthly rank bonus', th: 'ใช้โบนัสแรงค์รายเดือน' },
        'admin.settings.giftcard_rank_bonus_hint': { en: 'High financial impact. Keep disabled until real redemption costs are reviewed.', th: 'มีผลต่อการเงินสูง ควรปิดไว้จนกว่าจะตรวจต้นทุนการรับของขวัญจริง' },
        'admin.settings.giftcard_code_only_title': { en: 'Important code-only limitation', th: 'ข้อจำกัดสำคัญของการใช้รหัสอย่างเดียว' },
        'admin.settings.giftcard_code_only_desc': { en: 'The token and amount cannot be verified before redemption when only the 16-character code is supplied. Binance consumes an accepted code first, then returns its token and value. Exceptions are held for manual review.', th: 'เมื่อมีเพียงรหัส 16 ตัว จะตรวจเหรียญและมูลค่าก่อนรับไม่ได้ Binance จะรับรหัสก่อนแล้วจึงตอบเหรียญและมูลค่า รายการผิดเงื่อนไขจะถูกพักตรวจ' },
        'admin.settings.giftcard_save': { en: 'Save Gift Card settings', th: 'บันทึกการตั้งค่า Gift Card' },
        'admin.settings.giftcard_test': { en: 'Test saved API credentials', th: 'ทดสอบ API Key ที่บันทึกไว้' },
        'admin.settings.giftcard_success': { en: 'Binance Gift Card settings saved.', th: 'บันทึกการตั้งค่า Binance Gift Card แล้ว' },
        'admin.settings.giftcard_test_success': { en: 'Signed GET, RSA public-key access, and local OAEP encryption passed. This test does not call redeemCode or consume a Gift Card.', th: 'Signed GET การอ่าน RSA Public Key และการเข้ารหัส OAEP ภายในเซิร์ฟเวอร์ผ่านแล้ว การทดสอบนี้ไม่ได้เรียก redeemCode และไม่ใช้รหัส Gift Card' },
        'admin.settings.giftcard_error_local_rsa': { en: 'The server can read Binance RSA key but cannot produce the required encrypted code.', th: 'เซิร์ฟเวอร์อ่าน RSA Public Key ได้ แต่ไม่สามารถสร้างรหัสเข้ารหัสตามรูปแบบที่ Binance ต้องการ' },
        'admin.settings.giftcard_test_failed': { en: 'Unable to verify the saved Gift Card API credentials.', th: 'ไม่สามารถยืนยัน API Key สำหรับ Gift Card ที่บันทึกไว้' },
        'admin.settings.giftcard_error_mode': { en: 'Select a valid API credential mode.', th: 'กรุณาเลือกรูปแบบ API Key ที่ถูกต้อง' },
        'admin.settings.giftcard_error_roles': { en: 'Enable Gift Card access for at least one account type.', th: 'กรุณาเปิดให้ใช้งานอย่างน้อยหนึ่งประเภทบัญชี' },
        'admin.settings.giftcard_error_shared_credentials': { en: 'The existing Binance TRC20 API credentials are incomplete.', th: 'API Key ของ Binance TRC20 เดิมไม่ครบ' },
        'admin.settings.giftcard_error_credentials': { en: 'The Gift Card API credentials are missing or invalid.', th: 'API Key สำหรับ Gift Card ไม่ครบหรือรูปแบบไม่ถูกต้อง' },
        'admin.settings.giftcard_error_limits': { en: 'Check the minimum, maximum, and daily USDT limits.', th: 'กรุณาตรวจยอดขั้นต่ำ สูงสุด และวงเงินรายวัน' },
        'admin.settings.giftcard_error_credit_percent': { en: 'Credit percentage must be between 1 and 100.', th: 'เปอร์เซ็นต์เติมยอดต้องอยู่ระหว่าง 1 ถึง 100' },
        'admin.settings.giftcard_error_attempts': { en: 'Attempt limits must be between 1 and 4.', th: 'จำนวนครั้งที่อนุญาตต้องอยู่ระหว่าง 1 ถึง 4' },
        'admin.settings.giftcard_error_account_age': { en: 'Account age must be between 0 and 365 days.', th: 'อายุบัญชีต้องอยู่ระหว่าง 0 ถึง 365 วัน' },
        'admin.settings.giftcard_error_schema': { en: 'The Gift Card database table could not be prepared.', th: 'ไม่สามารถเตรียมตารางฐานข้อมูล Gift Card ได้' },
        'admin.settings.giftcard_error_clock': { en: 'Server time differs from Binance. Synchronize the server clock.', th: 'เวลาเซิร์ฟเวอร์ไม่ตรงกับ Binance กรุณาซิงก์เวลาเซิร์ฟเวอร์' },
        'admin.settings.giftcard_error_signature': { en: 'Binance rejected the API signature. Check the secret and signing configuration.', th: 'Binance ปฏิเสธลายเซ็น API กรุณาตรวจ Secret และการสร้างลายเซ็น' },
        'admin.settings.giftcard_error_provider_limit': { en: 'Binance has temporarily blocked further invalid redemption attempts.', th: 'Binance พักการลองรหัสผิดเพิ่มเติมชั่วคราว' },
        'admin.giftcard.title': { en: 'Binance Gift Card Audit', th: 'ตรวจสอบ Binance Gift Card' },
        'admin.giftcard.subtitle': { en: 'Full redemption and credit audit. Full redemption codes and API secrets are never displayed.', th: 'ตรวจรายการรับของขวัญและเติมยอดอย่างละเอียด โดยไม่แสดงรหัสเต็มหรือ API Secret' },
        'admin.giftcard.schema_error': { en: 'The Gift Card audit table is unavailable. Import the supplied schema or check database permissions.', th: 'ตารางตรวจสอบ Gift Card ไม่พร้อมใช้งาน กรุณานำเข้าไฟล์โครงสร้างหรือตรวจสิทธิ์ฐานข้อมูล' },
        'admin.giftcard.stats.total': { en: 'All requests', th: 'คำขอทั้งหมด' },
        'admin.giftcard.stats.completed': { en: 'Credited', th: 'เติมยอดแล้ว' },
        'admin.giftcard.stats.usdt': { en: 'Redeemed USDT', th: 'USDT ที่รับแล้ว' },
        'admin.giftcard.stats.credit': { en: 'Website credit', th: 'ยอดเติมเว็บไซต์' },
        'admin.giftcard.stats.review': { en: 'Needs review', th: 'รอตรวจสอบ' },
        'admin.giftcard.search_placeholder': { en: 'Search username, support code, IDs, API result, or last 4 characters', th: 'ค้นหาชื่อ รหัสตรวจสอบ ไอดี ผล API หรือท้ายรหัส 4 ตัว' },
        'admin.giftcard.filtered_count': { en: 'Matching records', th: 'รายการที่ตรงกับตัวกรอง' },
        'admin.giftcard.clear_filters': { en: 'Clear filters and show latest records', th: 'ล้างตัวกรองและแสดงรายการล่าสุด' },
        'admin.giftcard.list_fallback': { en: 'The audit list used compatibility mode. Diagnostic:', th: 'รายการตรวจสอบใช้โหมดรองรับฐานข้อมูลเดิม รหัสวินิจฉัย:' },
        'admin.giftcard.stage': { en: 'Processing stage', th: 'ขั้นตอนการทำงาน' },
        'admin.giftcard.all_statuses': { en: 'All statuses', th: 'ทุกสถานะ' },
        'admin.giftcard.empty': { en: 'No Gift Card redemptions found.', th: 'ไม่พบรายการรับ Gift Card' },
        'admin.giftcard.missing_user': { en: 'User not found', th: 'ไม่พบบัญชีผู้ใช้' },
        'admin.giftcard.reference': { en: 'Reference number', th: 'Reference Number' },
        'admin.giftcard.identity': { en: 'Identity number', th: 'Identity Number' },
        'admin.giftcard.transaction': { en: 'Website transaction', th: 'ธุรกรรมเว็บไซต์' },
        'admin.giftcard.api_result': { en: 'Safe API result', th: 'ผล API ที่ปลอดภัย' },
        'admin.giftcard.requested_at': { en: 'Requested', th: 'เวลาส่งรหัส' },
        'admin.giftcard.redeemed_at': { en: 'Redeemed by Binance', th: 'เวลาที่ Binance รับ' },
        'admin.giftcard.credited_at': { en: 'Website credited', th: 'เวลาเติมยอดเว็บไซต์' },
        'admin.giftcard.rate_percent': { en: 'Rate / credit percentage', th: 'เรต / เปอร์เซ็นต์เติมยอด' },
        'admin.giftcard.retry_credit': { en: 'Retry website credit', th: 'ดำเนินการเติมยอดเว็บไซต์อีกครั้ง' },
        'admin.giftcard.retry_confirm': { en: 'Retry only after confirming Binance already accepted this Gift Card. Continue?', th: 'ดำเนินการต่อเมื่อยืนยันแล้วว่า Binance รับ Gift Card นี้สำเร็จ ต้องการทำต่อหรือไม่' },
        'admin.giftcard.status.received': { en: 'Request received', th: 'รับคำขอแล้ว' },
        'admin.giftcard.status.preflight_error': { en: 'Preflight error', th: 'ตรวจสอบก่อนส่งไม่ผ่าน' },
        'admin.giftcard.status.redeeming': { en: 'Redeeming', th: 'กำลังรับของขวัญ' },
        'admin.giftcard.status.redeemed_pending_credit': { en: 'Redeemed, pending credit', th: 'รับแล้ว รอเติมยอด' },
        'admin.giftcard.status.completed': { en: 'Completed', th: 'สำเร็จ' },
        'admin.giftcard.status.invalid': { en: 'Invalid code', th: 'รหัสไม่ถูกต้อง' },
        'admin.giftcard.status.expired': { en: 'Expired', th: 'หมดอายุ' },
        'admin.giftcard.status.already_redeemed': { en: 'Already redeemed', th: 'ถูกใช้แล้ว' },
        'admin.giftcard.status.unsupported_token': { en: 'Non-USDT token', th: 'เหรียญไม่ใช่ USDT' },
        'admin.giftcard.status.amount_out_of_range': { en: 'Amount needs review', th: 'มูลค่าต้องตรวจสอบ' },
        'admin.giftcard.status.unknown_requires_review': { en: 'Unknown provider outcome', th: 'ไม่ทราบผลจาก Binance' },
        'admin.giftcard.status.provider_limit': { en: 'Provider limit reached', th: 'ถึงขีดจำกัด Binance' },
        'admin.giftcard.status.configuration_error': { en: 'Configuration error', th: 'การตั้งค่าผิดพลาด' },
        'admin.giftcard.status.rejected': { en: 'Rejected', th: 'ถูกปฏิเสธ' },
        'ranking.admin.status.disabled': { en: 'Bonus disabled by policy', th: 'ปิดโบนัสตามการตั้งค่า' },
        'users.btn.save': { en: 'Save', th: 'บันทึก' },

        // My Keys
        'mykeys.title': { en: 'My Keys', th: 'คีย์ของฉัน' },
        'mykeys.empty': { en: 'No keys yet', th: 'ยังไม่มีคีย์' },
        'mykeys.copy_success': { en: 'Copied!', th: 'คัดลอกแล้ว!' },
        'mykeys.buy_now': { en: 'Buy now', th: 'ซื้อเลย' },
        'mykeys.keys_unit': { en: 'keys', th: 'คีย์' },

        // History (more keys used by user/reseller history pages)
        'history.title': { en: 'History', th: 'ประวัติ' },
        'history.refill_title': { en: 'Refill History', th: 'ประวัติการเติมเงิน' },
        'history.refill_btn': { en: 'Refill History', th: 'ประวัติการเติมเงิน' },
        'history.total_refill': { en: 'Total Refill', th: 'ยอดเติมรวม' },
        'history.search_placeholder': { en: 'Search...', th: 'ค้นหา...' },
        'history.main_balance': { en: 'Main Balance:', th: 'ยอดเงินหลัก:' },
        'history.modal.title': { en: 'Detail', th: 'รายละเอียด' },
        'history.modal.purchase_date': { en: 'Purchase Date:', th: 'วันที่ซื้อ:' },
        'history.modal.license_keys': { en: 'License Keys:', th: 'คีย์ลิขสิทธิ์:' },
        'history.modal.amount_paid': { en: 'Amount Paid:', th: 'จำนวนที่จ่าย:' },
        'history.modal.copy_btn': { en: 'Copy', th: 'คัดลอก' },

        // Reseller dashboard
        'reseller.account_type': { en: 'Account Type', th: 'ประเภทบัญชี' },
        'reseller.recent_purchases': { en: 'Recent Purchases', th: 'รายการซื้อล่าสุด' },
        'reseller.no_keys_purchased': { en: 'No keys purchased', th: 'ยังไม่มีการซื้อคีย์' },
        'reseller.no_keys_purchased_start': { en: 'No purchases yet', th: 'ยังไม่มีรายการซื้อ' },

        // Products/profit misc
        'products.status.active': { en: 'Active', th: 'ใช้งาน' },
        'products.promo.no_fee': { en: '0% fee', th: 'ไม่มีค่าธรรมเนียม 0%' },
        'profit.rate': { en: 'Rate', th: 'เรท' },
        'category': { en: 'Category', th: 'หมวดหมู่' },

        // Login & Register
        'login.title': { th: 'เข้าสู่ระบบ', en: 'Login' },
        'login.desc': { th: 'เข้าสู่ระบบเพื่อเข้าถึงบัญชีของคุณ', en: 'Login to access your account' },
        'login.username': { th: 'ชื่อผู้ใช้', en: 'Username' },
        'login.password': { th: 'รหัสผ่าน', en: 'Password' },
        'login.placeholder.username': { th: 'กรอกชื่อผู้ใช้', en: 'Enter username' },
        'login.placeholder.password': { th: 'กรอกรหัสผ่าน', en: 'Enter password' },
        'login.btn': { th: 'เข้าสู่ระบบ', en: 'Login' },
        'login.no_account': { th: 'ยังไม่มีบัญชีใช่ไหม?', en: "Don't have an account?" },
        'login.banned': { th: 'บัญชีของคุณถูกระงับการใช้งาน', en: 'Your account has been banned' },
        'login.error': { th: 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ', en: 'An error occurred during login' },

        'register.title': { th: 'สร้างบัญชี', en: 'Create Account' },
        'register.desc': { th: 'เข้าร่วมกับเราวันนี้', en: 'Join us today' },
        'register.email': { th: 'อีเมล', en: 'Email' },
        'register.confirm_password': { th: 'ยืนยันรหัสผ่าน', en: 'Confirm Password' },
        'register.placeholder.email': { th: 'กรอกอีเมล', en: 'Enter email' },
        'register.placeholder.confirm': { th: 'ยืนยันรหัสผ่าน', en: 'Confirm password' },
        'register.btn': { th: 'สมัครสมาชิก', en: 'Register' },
        'register.have_account': { th: 'มีบัญชีอยู่แล้วใช่ไหม?', en: 'Already have an account?' },
        'register.error.mismatch': { th: 'รหัสผ่านไม่ตรงกัน', en: 'Passwords do not match' },
        'register.error.rate_limit': { th: 'สมัครสมาชิกบ่อยเกินไป กรุณารอประมาณ {minutes} นาที', en: 'Too many registration attempts. Please wait about {minutes} minute(s).' },
        'register.error.failed': { th: 'ไม่สามารถสมัครสมาชิกได้', en: 'Registration could not be completed.' },
        'register.success.login': { th: 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ', en: 'Registration completed. Please sign in.' },

        // Admin Dashboard
        'admin.dashboard.title': { th: 'หน้าจัดการ - ผู้ดูแลระบบ', en: 'Store - Admin' },
        'admin.dashboard.success_purchase': { th: 'ซื้อสำเร็จ {count} ชิ้น รวม {total}', en: 'Successfully purchased {count} items for {total}' },
        'admin.dashboard.error.invalid_request': { th: 'คำขอไม่ถูกต้อง', en: 'Invalid request' },
        'admin.dashboard.error.purchase_failed': { th: 'การซื้อล้มเหลว', en: 'Purchase failed' },
        'admin.dashboard.error_no_keys': { th: 'ไม่มีคีย์ว่างพร้อมจำหน่าย', en: 'No keys available' },
        'admin.dashboard.error_not_enough': { th: 'สินค้ามีไม่เพียงพอ', en: 'Not enough keys available' },

        // Admin Users (New keys)
        'admin.users.heading': { th: 'จัดการผู้ใช้', en: 'Manage Users' },
        'admin.users.add_user_btn': { th: 'เพิ่มผู้ใช้', en: 'Add User' },
        'admin.users.modal.add_title': { th: 'เพิ่มผู้ใช้ใหม่', en: 'Add New User' },
        'admin.users.modal.initial_balance': { th: 'ยอดเงินเริ่มต้น', en: 'Initial Balance' },
        'admin.users.modal.balance_title': { th: 'แก้ไขยอดเงิน', en: 'Update Balance' },
        'admin.users.modal.user_label': { th: 'ผู้ใช้:', en: 'User:' },
        'admin.users.modal.current_balance': { th: 'ยอดเงินปัจจุบัน:', en: 'Current Balance:' },
        'admin.users.modal.amount_to_add': { th: 'จำนวนเงินที่เพิ่ม/ลด', en: 'Amount to Add/Deduct' },
        'admin.users.modal.new_balance': { th: 'ยอดเงินใหม่:', en: 'New Balance:' },

        // Admin Settings (New keys)
        'admin.settings.general_title': { th: 'ตั้งค่าทั่วไป', en: 'General Settings' },
        'admin.settings.site_name': { th: 'ชื่อเว็บไซต์', en: 'Site Name' },
        'admin.settings.currency_symbol': { th: 'สัญลักษณ์เงิน', en: 'Currency Symbol' },
        'admin.settings.currency_name': { th: 'ชื่อย่อเงิน', en: 'Currency Name' },
        'admin.settings.currency_selection': { th: 'เลือกสกุลเงิน', en: 'Select Currency' },
        'admin.settings.currency_hint': { th: 'เลือกสกุลเงินหลัก (หากเป็น THB จะรับสลิปบาท หากเป็น USD จะแปลงให้อัตโนมัติ)', en: 'Select primary currency (Note: THB for slips, USD for auto-convert)' },
        'admin.settings.error.currency_change_requires_migration': { th: 'ไม่สามารถเปลี่ยนสกุลเงินฐานได้หลังมีข้อมูลยอดเงิน ราคา หรือธุรกรรม ต้องแปลงข้อมูลฐานทั้งหมดก่อน', en: 'The base currency cannot be changed after balances, prices, or transactions exist. Migrate stored monetary data first.' },
        'admin.settings.currency.thb': { th: 'เงินบาทไทย (THB - ฿)', en: 'Thai Baht (THB - ฿)' },
        'admin.settings.currency.usd': { th: 'ดอลลาร์สหรัฐ (USD - $)', en: 'US Dollar (USD - $)' },
        'admin.settings.currency.inr': { th: 'รูปีอินเดีย (INR - ₹)', en: 'Indian Rupee (INR - ₹)' },
        'admin.settings.currency.eur': { th: 'ยูโร (EUR - €)', en: 'Euro (EUR - €)' },
        'admin.settings.currency.gbp': { th: 'ปอนด์สเตอลิงก์ (GBP - £)', en: 'British Pound (GBP - £)' },
        'admin.settings.currency.vnd': { th: 'ดงเวียดนาม (VND - ₫)', en: 'Vietnamese Dong (VND - ₫)' },
        'admin.settings.currency.php': { th: 'เปโซฟิลิปปินส์ (PHP - ₱)', en: 'Philippine Peso (PHP - ₱)' },
        'admin.settings.currency.myr': { th: 'ริงกิตมาเลเซีย (MYR - RM)', en: 'Malaysian Ringgit (MYR - RM)' },
        'admin.settings.currency.jpy': { th: 'เยนญี่ปุ่น (JPY - ¥)', en: 'Japanese Yen (JPY - ¥)' },
        'admin.settings.currency.cny': { th: 'หยวนจีน (CNY - ¥)', en: 'Chinese Yuan (CNY - ¥)' },
        'admin.settings.currency.krw': { th: 'วอนเกาหลีใต้ (KRW - ₩)', en: 'South Korean Won (KRW - ₩)' },
        'admin.settings.store_title': { th: 'แบรนด์ดิ้งร้านค้า', en: 'Store Branding' },
        'admin.settings.store_title_desc': { th: 'ปรับแต่งหัวข้อร้านค้า สี และสไตล์', en: 'Customize store title, color, and style' },
        'admin.settings.store_title_text': { th: 'ข้อความหัวข้อ', en: 'Title Text' },
        'admin.settings.store_title_color': { th: 'สีของหัวข้อ', en: 'Title Color' },
        'admin.settings.store_title_style': { th: 'สไตล์หัวข้อ', en: 'Title Style' },
        'admin.settings.title_image': { th: 'รูปภาพหัวข้อ (PNG)', en: 'Title Image (PNG)' },
        'admin.settings.user_title_image': { th: 'รูปหัวข้อฝั่งผู้ใช้', en: 'User Title Image' },
        'admin.settings.reseller_title_image': { th: 'รูปหัวข้อฝั่งตัวแทน', en: 'Reseller Title Image' },
        'admin.settings.image_hint': { th: 'เฉพาะไฟล์ .png เท่านั้น', en: 'PNG only' },
        'admin.settings.save_store_title': { th: 'บันทึกแบรนด์ดิ้ง', en: 'Save Branding' },
        'admin.settings.save_general': { th: 'บันทึกการตั้งค่าทั่วไป', en: 'Save General Settings' },
        'admin.settings.announcement_title': { th: 'ประกาศ (Marquee)', en: 'Announcement Settings' },
        'admin.settings.announcement_desc': { th: 'ตั้งค่าข้อความประกาศวิ่งที่หน้าซื้อสินค้า', en: 'Configure scrolling announcement text' },

        // Reseller Extras
        'reseller.buy_keys_btn': { th: 'ซื้อสินค้าตัวแทน', en: 'Buy Keys' },
        'reseller.dashboard.title': { th: 'หน้าหลักตัวแทน', en: 'Reseller Dashboard' },
        
        // Buy Success Modal
        'buy.purchase_successful': { th: 'ซื้อสินค้าสำเร็จ', en: 'Purchase Successful' },
        'buy.product_name': { th: 'ชื่อสินค้า:', en: 'Product Name:' },
        'buy.license_keys': { th: 'คีย์สินค้า:', en: 'License Keys:' },
        'buy.amount_paid': { th: 'จำนวนที่ชำระ:', en: 'Amount Paid:' },
        'buy.download_file': { th: 'ดาวน์โหลดไฟล์', en: 'Download File' },
        'buy.copy_all': { th: 'คัดลอกทั้งหมด', en: 'Copy All' },
        'buy.copy_success': { th: 'คัดลอกแล้ว!', en: 'Copied!' },
        'buy.confirm_purchase_title': { th: 'ยืนยันการสั่งซื้อ', en: 'Confirm Purchase' },
        'buy.price_per_item': { th: 'ราคาต่อชิ้น:', en: 'Price per item:' },
        'buy.quantity': { th: 'จำนวน:', en: 'Quantity:' },
        'buy.total_price': { th: 'ราคารวม:', en: 'Total Price:' },
        'buy.balance_after': { th: 'ยอดคงเหลือหลังซื้อ:', en: 'Balance after purchase:' },
        'buy.confirm': { th: 'ยืนยัน', en: 'Confirm' },
        'buy.cancel': { th: 'ยกเลิก', en: 'Cancel' },
        'buy.insufficient_balance': { th: 'ยอดเงินไม่เพียงพอ!', en: 'Insufficient balance!' },

        // Admin Out of Stock
        'admin.out_of_stock.heading': { th: 'สินค้าหมด', en: 'Out of Stock' },
        'admin.out_of_stock.desc': { th: 'แสดงรายการสินค้าที่ยอดคงเหลือเป็น 0', en: 'Showing variants with 0 stock' },
        'admin.out_of_stock.empty': { th: 'ไม่มีสินค้าที่หมด', en: 'No out-of-stock variants found.' },
        'admin.out_of_stock.table.product': { th: 'สินค้า', en: 'Product' },
        'admin.out_of_stock.table.variant': { th: 'รูปแบบ', en: 'Variant' },
        'admin.out_of_stock.table.user_price': { th: 'ราคาผู้ใช้', en: 'User Price' },
        'admin.out_of_stock.table.reseller_price': { th: 'ราคาตัวแทน', en: 'Reseller Price' },
        'admin.out_of_stock.table.total_keys': { th: 'คีย์ทั้งหมด', en: 'Total Keys' },
        'admin.out_of_stock.table.available': { th: 'คงเหลือ', en: 'Available' },
        'admin.out_of_stock.table.action': { th: 'จัดการ', en: 'Action' },
        'admin.out_of_stock.add_keys': { th: 'เพิ่มคีย์', en: 'Add Keys' },
        'admin.out_of_stock.modal.title': { th: 'เพิ่มคีย์สู่รูปแบบสินค้า', en: 'Add Keys to Variant' },
        'admin.out_of_stock.modal.product': { th: 'สินค้า:', en: 'Product:' },
        'admin.out_of_stock.modal.variant': { th: 'รูปแบบ:', en: 'Variant:' },
        'admin.out_of_stock.modal.key_placeholder': { th: 'กรอกรหัสคีย์ (1 บรรทัดต่อ 1 คีย์)', en: 'Enter key codes, one per line' },
        'admin.out_of_stock.modal.cancel': { th: 'ยกเลิก', en: 'Cancel' },
        'admin.out_of_stock.modal.add': { th: 'เพิ่มคีย์', en: 'Add Keys' },

        // ==========================================
        // Missing/Generated Keys
        // ==========================================
        'account.error.email_used': { en: 'Username or email already in use', th: 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้งานแล้ว' },
        'account.error.password': { en: 'Current password is incorrect', th: 'รหัสผ่านปัจจุบันไม่ถูกต้อง' },
        'account.error.username_format': { en: 'Username must be 3-60 characters and use only letters, numbers, dot, dash, or underscore', th: 'ชื่อผู้ใช้ต้องมี 3-60 ตัวอักษร และใช้ได้เฉพาะตัวอักษร ตัวเลข จุด ขีดกลาง หรือขีดล่าง' },
        'account.error.invalid_email': { en: 'Invalid email address', th: 'รูปแบบอีเมลไม่ถูกต้อง' },
        'account.error.password_short': { en: 'New password must be at least 8 characters', th: 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร' },
        'account.error.password_mismatch': { en: 'New password confirmation does not match', th: 'การยืนยันรหัสผ่านใหม่ไม่ตรงกัน' },
        'account.error.update_failed': { en: 'Failed to update account', th: 'ไม่สามารถอัปเดตบัญชีได้' },
        'account.success.update': { en: 'Account updated successfully', th: 'อัปเดตข้อมูลบัญชีเรียบร้อยแล้ว' },
        'admin.binance.heading': { en: 'Binance Pay Deposits', th: 'รายการเติมเงิน Binance' },
        'admin.binance.table.thb': { en: 'Amount (THB)', th: 'ยอดเงิน (บาท)' },
        'admin.categories.error.update': { en: 'Failed to update download URL.', th: 'ไม่สามารถอัปเดตลิงก์ดาวน์โหลดได้' },
        'admin.categories.error.invalid_url': { en: 'Download URL must be a valid HTTPS URL.', th: 'ลิงก์ดาวน์โหลดต้องเป็น URL แบบ HTTPS ที่ถูกต้อง' },
        'admin.categories.success.update': { en: "Download URL for category '{name}' updated successfully.", th: "อัปเดตลิงก์ดาวน์โหลดสำหรับหมวดหมู่ '{name}' เรียบร้อยแล้ว" },
        'admin.products.error.add': { en: 'Failed to add product', th: 'ไม่สามารถเพิ่มสินค้าได้' },
        'admin.products.error.delete': { en: 'Failed to delete product', th: 'ไม่สามารถลบทิ้งได้' },
        'admin.products.error.duration_required': { en: 'Duration is required', th: 'กรุณากรอกระยะเวลา' },
        'admin.products.error.edit': { en: 'Failed to update product', th: 'ไม่สามารถอัปเดตสินค้าได้' },
        'admin.products.error.name_required': { en: 'Product name is required', th: 'กรุณากรอกชื่อสินค้า' },
        'admin.products.error.variant_add': { en: 'Failed to add variant', th: 'ไม่สามารถเพิ่มรูปแบบสินค้าได้' },
        'admin.products.modal.variant_label': { en: 'Variant:', th: 'รูปแบบ:' },
        'admin.products.success.add': { en: 'Product added successfully', th: 'เพิ่มสินค้าสำเร็จแล้ว' },
        'admin.products.success.delete': { en: 'Product deleted successfully', th: 'ลบสินค้าสำเร็จแล้ว' },
        'admin.products.success.edit': { en: 'Product updated successfully', th: 'อัปเดตสินค้าสำเร็จแล้ว' },
        'admin.products.success.status': { en: 'Product status updated successfully', th: 'อัปเดตสถานะสินค้าสำเร็จแล้ว' },
        'admin.products.success.variant_add': { en: 'Variant added successfully', th: 'เพิ่มรูปแบบสินค้าสำเร็จแล้ว' },
        'admin.products.variant_label': { en: 'Variant', th: 'รูปแบบ' },
        'admin.settings.api_key': { en: 'API Key', th: 'API Key' },
        'admin.settings.easyslip_api_hint': { en: 'Get your API Key from easyslip.com', th: 'รับ API Key ได้ที่ easyslip.com' },
        'admin.settings.easyslip_api_placeholder': { en: 'Your EasySlip API Key', th: 'รหัส EasySlip API' },
        'admin.settings.placeholder.api_key': { en: 'Enter API Key', th: 'กรอก API Key' },
        'admin.settings.placeholder.confirm_password': { en: 'Confirm Password', th: 'ยืนยันรหัสผ่าน' },
        'admin.settings.placeholder.leave_blank': { en: 'Leave blank to keep current', th: 'เว้นว่างไว้' },
        'admin.settings.placeholder.secret_key': { en: 'Enter Secret Key', th: 'กรอก Secret Key' },
        'admin.settings.placeholder.token': { en: 'Paste your token here', th: 'วางโทเค็นของคุณที่นี่' },
        'admin.settings.placeholder.url': { en: 'Leave empty for default', th: 'ปล่อยว่างเพื่อใช้ค่าเริ่มต้น' },
        'admin.settings.placeholder.wallet': { en: 'Enter Wallet Address', th: 'กรอกที่อยู่กระเป๋า' },
        'admin.settings.receiver_name_en_hint': { en: 'Name on slip (English)', th: 'ชื่อในสลิป (อังกฤษ)' },
        'admin.settings.receiver_name_th_hint': { en: 'Name on slip (Thai)', th: 'ชื่อในสลิป (ไทย)' },
        'admin.settings.save_btn': { en: 'Save Settings', th: 'บันทึกการตั้งค่า' },
        'admin.settings.success.account': { en: 'Account updated successfully', th: 'อัปเดตข้อมูลบัญชีเรียบร้อยแล้ว' },
        'admin.settings.success.announcement': { en: 'Announcement text saved successfully!', th: 'บันทึกข้อความประกาศเรียบร้อยแล้ว' },
        'admin.settings.success.binance': { en: 'Binance settings saved successfully!', th: 'บันทึกการตั้งค่า Binance เรียบร้อยแล้ว!' },
        'admin.settings.success.branding': { en: 'Store title updated successfully!', th: 'อัปเดตชื่อร้านค้าเรียบร้อยแล้ว!' },
        'admin.settings.success.easyslip': { en: 'EasySlip settings saved successfully!', th: 'บันทึกการตั้งค่า EasySlip เรียบร้อยแล้ว!' },
        'admin.settings.success.general': { en: 'General settings saved successfully!', th: 'บันทึกการตั้งค่าทั่วไปเรียบร้อยแล้ว' },
        'admin.settings.success.promptpay': { en: 'PromptPay settings saved successfully!', th: 'บันทึกการตั้งค่าพร้อมเพย์เรียบร้อยแล้ว!' },
        'admin.settings.success.truemoney': { en: 'TrueMoney Angpao settings saved successfully!', th: 'บันทึกการตั้งค่าทรูมันนี่เรียบร้อยแล้ว!' },
        'admin.users.error.add': { en: 'Failed to add user', th: 'ไม่สามารถเพิ่มผู้ใช้ได้' },
        'admin.users.error.delete': { en: 'Failed to delete user', th: 'ไม่สามารถลบผู้ใช้ได้' },
        'admin.users.error.fields_required': { en: 'All fields are required', th: 'กรุณากรอกข้อมูลให้ครบทุกช่อง' },
        'admin.users.success.add': { en: 'User added successfully', th: 'เพิ่มผู้ใช้สำเร็จแล้ว' },
        'admin.users.success.balance_add': { en: 'Balance added successfully', th: 'เพิ่มยอดเงินสำเร็จแล้ว' },
        'admin.users.success.balance_deduct': { en: 'Balance deducted successfully', th: 'หักยอดเงินสำเร็จแล้ว' },
        'admin.users.success.delete': { en: 'User deleted successfully', th: 'ลบผู้ใช้สำเร็จแล้ว' },
        'buy.error.not_enough': { en: 'Not enough keys available', th: 'คีย์มีจำนวนไม่เพียงพอ' },
        'buy.success.purchase': { en: '{count} key(s) purchased successfully for {total}!', th: 'ซื้อสำเร็จ {count} คีย์ เป็นจำนวนเงิน {total}!' },
        'common.copy_key': { en: 'Copy Key', th: 'คัดลอกคีย์' },
        'common.date_format': { en: 'Y-m-d H:i', th: 'd/m/Y H:i' },
        'common.default_announcement': { en: '🎖️ Welcome to the official SPWZ shop 🎖️', th: '🎖️ยินดีต้อนรับเข้าสู่ร้าน SPWZ อย่างเป็นทางการ 🎖️' },
        'common.error.invalid_email': { en: 'Invalid email address', th: 'ที่อยู่อีเมลไม่ถูกต้อง' },
        'common.error.invalid_request': { en: 'Invalid request', th: 'คำขอไม่ถูกต้อง' },
        'common.error.operation': { en: 'Operation failed', th: 'การดำเนินการล้มเหลว' },
        'common.error.password_mismatch': { en: 'New password confirmation does not match', th: 'การยืนยันรหัสผ่านใหม่ไม่ตรงกัน' },
        'common.error.password_short': { en: 'New password must be at least 6 characters', th: 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร' },
        'common.error.update_failed': { en: 'Failed to update account', th: 'ไม่สามารถอัปเดตบัญชีได้' },
        'common.error.user_not_found': { en: 'User not found', th: 'ไม่พบรายชื่อผู้ใช้' },
        'common.lang.en': { en: 'English', th: 'English' },
        'common.lang.th': { en: 'Thai', th: 'ภาษาไทย' },
        'common.no_duration': { en: 'No Duration', th: 'ไม่มีระยะเวลา' },
        'common.placeholder.code': { en: 'Enter Code', th: 'ระบุโค้ด' },
        'common.placeholder.nominal': { en: 'Enter Amount', th: 'ระบุจำนวนเงิน' },
        'common.placeholder.url': { en: 'https://...', th: 'https://...' },
        'dashboard.status.active': { en: 'Active', th: 'ใช้งานอยู่' },
        'dashboard.status.banned': { en: 'Banned', th: 'ถูกระงับ' },
        'deposit.bank_default': { en: 'KBank', th: 'กสิกรไทย' },
        'deposit.bank_name': { en: 'KBank', th: 'กสิกรไทย' },
        'deposit.error.add_balance_failed': { en: 'Unable to credit balance to account.', th: 'ไม่สามารถเพิ่มยอดเงินได้' },
        'deposit.error.binance_disabled': { en: 'Binance deposit system is not currently enabled.', th: 'ระบบเติมเงินผ่าน Binance ยังไม่เปิดใช้งาน' },
        'deposit.error.binance_tx_processing': { en: 'This TxID is currently being processed. Please wait.', th: 'กำลังประมวลผล TxID นี้อยู่ กรุณารอสักครู่' },
        'deposit.error.binance_tx_used': { en: 'This TxID has already been used for a deposit. Duplicate use is prohibited.', th: 'TxID นี้เคยถูกใช้เติมเงินแล้ว ห้ามใช้ซ้ำ' },
        'deposit.error.easyslip_not_configured': { en: 'Receiver account not configured. Please contact admin.', th: 'ยังไม่ได้ตั้งค่าบัญชีผู้รับเงิน กรุณาตรวจสอบในหน้าตั้งค่า' },
        'deposit.error.enter_txid': { en: 'Please enter Transaction ID', th: 'กรุณากรอก Transaction ID' },
        'deposit.error.exchange_rate_failed': { en: 'Unable to fetch current exchange rate.', th: 'ไม่สามารถแปลงอัตราแลกเปลี่ยนได้' },
        'deposit.error.general': { en: 'An unexpected error occurred.', th: 'เกิดข้อผิดพลาดที่ไม่คาดคิด' },
        'deposit.error.invalid': { en: 'Invalid deposit data.', th: 'ข้อมูลการเติมเงินไม่ถูกต้อง' },
        'deposit.error.method_not_allowed': { en: 'Method not allowed.', th: 'เรียกใช้งานไม่ถูกต้อง' },
        'deposit.error.nourl': { en: 'Payment URL not found.', th: 'ไม่พบ URL สำหรับชำระเงิน' },
        'deposit.error.pending': { en: 'You have a pending transaction. Please wait.', th: 'คุณมีรายการที่ค้างอยู่ กรุณารอสักครู่' },
        'deposit.error.save_failed': { en: 'Unable to save deposit data.', th: 'ไม่สามารถบันทึกข้อมูลการฝากเงินได้' },
        'deposit.error.slip_hash_used': { en: 'This slip image has already been submitted. Duplicate slips are prohibited.', th: 'สลิปนี้เคยถูกส่งเข้าระบบไปแล้ว ห้ามใช้สลิปซ้ำ' },
        'deposit.error.slip_invalid_format': { en: 'Invalid slip image format.', th: 'รูปแบบไฟล์รูปภาพสลิปไม่ถูกต้อง' },
        'deposit.error.slip_used': { en: 'This slip has already been used.', th: 'สลิปนี้เคยใช้แล้ว' },
        'deposit.error.txinit': { en: 'Failed to initialize transaction.', th: 'ไม่สามารถสร้างรายการธุรกรรมได้' },
        'deposit.receiver_name': { en: '{en}', th: '{th}' },
        'deposit.success_msg': { en: 'Deposit initialized successfully!', th: 'เริ่มรายการเติมเงินเรียบร้อยแล้ว!' },
        'key': { en: 'Key', th: 'คีย์' },
        'nav.reseller': { en: 'Reseller Panel', th: 'ระบบตัวแทน' },
        'register.username': { en: 'Username', th: 'ชื่อผู้ใช้' },

        // Store catalogue / dashboard categories
        'catalog.browse_title': { th: 'เลือกสินค้าตามหมวดหมู่', en: 'Browse Products by Category' },
        'catalog.browse_desc': { th: 'เลือกแพลตฟอร์มหรือหมวดหมู่ แล้วไปยังหน้าสินค้าที่กรองไว้ทันที', en: 'Choose a platform or category and open the store with matching products.' },
        'catalog.platform_title': { th: 'แพลตฟอร์ม / รูปแบบสินค้า', en: 'Platform / Product Type' },
        'catalog.platform_desc': { th: 'เลือก Android, iOS, ทั้งสองระบบ หรือสินค้าแบบ Account', en: 'Choose Android, iOS, both platforms, or Account products.' },
        'catalog.category_title': { th: 'หมวดหมู่สินค้า', en: 'Product Categories' },
        'catalog.category_desc': { th: 'หมวดหมู่ทั้งหมดจะแสดงแบบตารางและไม่ต้องเลื่อนด้านข้าง', en: 'All categories are shown in a responsive grid without horizontal scrolling.' },
        'catalog.all_platforms': { th: 'ทุกแพลตฟอร์ม', en: 'All Platforms' },
        'catalog.both_platforms': { th: 'ทั้งสองระบบ', en: 'Android + iOS' },
        'catalog.account_products': { th: 'Account', en: 'Account' },
        'catalog.products_count': { th: '{count} สินค้า', en: '{count} products' },
        'catalog.open_store': { th: 'เปิดหน้าสินค้า', en: 'Open Store' },
        'catalog.no_categories': { th: 'ยังไม่มีหมวดหมู่สินค้าที่เปิดใช้งาน', en: 'No active product categories yet.' },
        'catalog.total_products': { th: 'สินค้าที่เปิดใช้งานทั้งหมด', en: 'Total Active Products' },

        // Settings additions
        'admin.settings.site_base_url': { th: 'โดเมนเว็บไซต์นี้ (Base URL)', en: 'This Website Base URL' },
        'admin.settings.site_base_url_hint': { th: 'ใช้ HTTPS และไม่ใส่ path ต่อท้าย', en: 'Use HTTPS and do not include a trailing path.' },
        'admin.settings.secret_not_shown': { th: 'ระบบจะไม่แสดงค่าลับเดิมกลับมาในหน้าเว็บ', en: 'Existing secrets are never displayed on this page.' },
        'admin.settings.bank_name_th': { th: 'ชื่อธนาคาร (ไทย)', en: 'Bank Name (Thai)' },
        'admin.settings.bank_name_en': { th: 'ชื่อธนาคาร (อังกฤษ/รหัส)', en: 'Bank Name (English / Code)' },
        'admin.settings.bank_name_th_placeholder': { th: 'เช่น กรุงไทย', en: 'Example: Krungthai' },
        'admin.settings.bank_name_en_placeholder': { th: 'เช่น KTB', en: 'Example: KTB' },
        'admin.settings.bank_name_th_hint': { th: 'ใช้แสดงในหน้า Mobile Banking', en: 'Displayed on the Mobile Banking instructions.' },
        'admin.settings.bank_name_en_hint': { th: 'ใช้เมื่อเว็บไซต์แสดงภาษาอังกฤษ', en: 'Used when the website is displayed in English.' },
        'admin.settings.slip_max_age': { th: 'อายุสลิปสูงสุด (นาที)', en: 'Maximum Slip Age (Minutes)' },
        'admin.settings.slip_max_age_hint': { th: 'แนะนำ 1440 นาที (24 ชั่วโมง) เพื่อป้องกันการใช้สลิปเก่า', en: 'Recommended: 1440 minutes (24 hours) to prevent old slips from being reused.' },
        'admin.settings.keep_api_key_placeholder': { th: 'เว้นว่างเพื่อใช้ API Key เดิม', en: 'Leave blank to keep the current API key' },
        'admin.settings.keep_secret_key_placeholder': { th: 'เว้นว่างเพื่อใช้ Secret Key เดิม', en: 'Leave blank to keep the current secret key' },
        'admin.settings.secret_replace_hint': { th: 'ระบบจะเก็บค่าเดิมไว้ และเปลี่ยนเฉพาะเมื่อกรอกค่าใหม่', en: 'The current value is kept unless a new value is entered.' },
        'admin.settings.current_image': { th: 'รูปปัจจุบัน', en: 'Current image' },
        'admin.settings.remove_image': { th: 'ลบรูป', en: 'Remove image' },
        'admin.settings.undo_remove': { th: 'ยกเลิกการลบ', en: 'Undo removal' },
        'admin.settings.image_remove_pending': { th: 'รูปนี้จะถูกลบเมื่อกดบันทึก', en: 'This image will be removed when you save.' },
        'admin.settings.image_hint_full': { th: 'รองรับ PNG ไม่เกิน 2 MB ขนาดสูงสุด 2048×2048 พิกเซล', en: 'PNG only, up to 2 MB and 2048×2048 pixels.' },
        'admin.settings.image_preview_alt': { th: 'ตัวอย่างรูปหัวข้อ', en: 'Title image preview' },
        'admin.settings.no_image': { th: 'ยังไม่ได้ตั้งค่ารูป', en: 'No image configured' },
        'admin.settings.image_file_missing': { th: 'ไม่พบไฟล์เดิม กรุณาลบค่าหรืออัปโหลดรูปใหม่', en: 'The saved file is missing. Remove it or upload a new image.' },
    },

    getStoredLanguage: function () {
        try {
            return window.localStorage ? window.localStorage.getItem('app_language') : null;
        } catch (e) {
            return null;
        }
    },

    storeLanguage: function (lang) {
        try {
            if (window.localStorage) {
                window.localStorage.setItem('app_language', lang);
            }
        } catch (e) {
            // Storage may be blocked by privacy settings. The PHP session and
            // cookie still keep the selected language, so this is non-fatal.
        }
    },

    // Initialize language system. PHP/session is the authoritative source on
    // server-rendered pages. localStorage is only a fallback for standalone
    // client-rendered content; it must never override a language the user just
    // selected through toggle_lang.php.
    init: function (options) {
        if (options && typeof options === 'object') {
            const parsedRate = parseFloat(options.exchangeRate);
            if (Number.isFinite(parsedRate) && parsedRate > 0) {
                this.exchangeRate = parsedRate;
            }
        } else if (options) {
            const parsedRate = parseFloat(options);
            if (Number.isFinite(parsedRate) && parsedRate > 0) {
                this.exchangeRate = parsedRate;
            }
        }

        const normalizeLanguage = (value) => {
            if (typeof value !== 'string') return null;
            const normalized = value.trim().toLowerCase().split(/[-_]/)[0];
            return this.languages[normalized] ? normalized : null;
        };

        // Prefer the explicit PHP value. If a page does not define PHP_LANG,
        // <html lang="..."> is still server-rendered and must take priority
        // over stale localStorage from another device/session.
        const explicitServerLang = normalizeLanguage(window.PHP_LANG);
        const documentLang = normalizeLanguage(document.documentElement.getAttribute('lang'));
        const serverLang = explicitServerLang || documentLang;
        const savedLang = normalizeLanguage(this.getStoredLanguage());
        const resolvedLang = serverLang || savedLang || 'th';
        const wasInitialized = Boolean(this.initialized);

        this.current = resolvedLang;
        this.storeLanguage(resolvedLang);
        document.documentElement.lang = resolvedLang;

        // updatePage() now skips unknown keys and preserves server-rendered
        // fallback text. It is therefore safe and necessary to run on every
        // page, including pages that still contain English fallback text.
        this.updatePage();
        this.updateSwitcherUI();
        this.initialized = true;

        if (!wasInitialized) {
            document.dispatchEvent(new CustomEvent('langReady', { detail: { lang: this.current } }));
        }
    },

    // Set language
    setLanguage: function (lang) {
        if (this.languages[lang]) {
            this.current = lang;
            this.storeLanguage(lang);
            document.documentElement.lang = lang;
            this.updatePage();
            this.updateSwitcherUI();
            document.dispatchEvent(new CustomEvent('languageChanged', { detail: { lang } }));
            console.log('[Lang] Language changed to:', lang);
        }
    },

    // Toggle between languages
    toggle: function () {
        const newLang = this.current === 'en' ? 'th' : 'en';
        this.setLanguage(newLang);
    },

    /**
     * Format currency using configured app currency (not language).
     * @param {number|string} amount Amount in configured currency
     * @param {boolean} ignoreConversion Whether to ignore language-based conversion
     * @returns {string} Formatted currency string
     */
    formatCurrency: function (amount, ignoreConversion = false) {
        amount = parseFloat(amount);
        if (!Number.isFinite(amount)) return 'N/A';

        const baseCurrency = (window.APP_CURRENCY_NAME != null && String(window.APP_CURRENCY_NAME) !== '')
            ? String(window.APP_CURRENCY_NAME).toUpperCase()
            : 'THB';
        if (baseCurrency !== 'THB' && baseCurrency !== 'USD') {
            return 'N/A';
        }
        const baseSymbol = baseCurrency === 'THB' ? '฿' : '$';
        const locale = (this.current === 'th') ? 'th-TH' : 'en-US';
        const render = (symbol, value) => symbol + value.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        if (ignoreConversion) {
            return render(baseSymbol, amount);
        }

        const rate = Number.parseFloat(this.exchangeRate);
        if (this.current === 'en') {
            if (baseCurrency === 'USD') return render('$', amount);
            if (!Number.isFinite(rate) || rate <= 0) return 'Rate unavailable';
            return render('$', amount * rate);
        }

        if (baseCurrency === 'THB') return render('฿', amount);
        if (!Number.isFinite(rate) || rate <= 0) return 'อัตราแลกเปลี่ยนไม่พร้อมใช้งาน';
        return render('฿', amount / rate);
    },

    // consolidated copy will be at the end

    hasTranslation: function (key) {
        return Boolean(key && Object.prototype.hasOwnProperty.call(this.translations, key));
    },

    // Get translation (supports {placeholders}). A caller may provide fallback
    // text so missing keys never leak their internal identifier into the UI.
    t: function (key, params = null, fallback = null) {
        const translation = this.translations[key];
        if (!translation) {
            return fallback !== null ? String(fallback) : String(key || '');
        }

        let text = translation[this.current] || translation.en || translation.th || fallback || key;
        if (params && typeof params === 'object') {
            Object.keys(params).forEach(k => {
                text = text.split('{' + k + '}').join(String(params[k]));
            });
        }
        return String(text);
    },

    // Update all elements on the page with data-lang attributes. Unknown
    // keys are intentionally skipped so server-rendered fallback text survives
    // an old browser cache or an incomplete client dictionary.
    updatePage: function () {
        const langElements = document.querySelectorAll('[data-lang], [data-lang-placeholder], [data-lang-title]');

        langElements.forEach(element => {
            const paramsRaw = element.getAttribute('data-lang-params');
            let params = null;
            if (paramsRaw) {
                try {
                    const parsed = JSON.parse(paramsRaw);
                    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                        params = parsed;
                    }
                } catch (e) {
                    params = null;
                }
            }

            if (element.hasAttribute('data-lang')) {
                const key = element.getAttribute('data-lang');
                if (this.hasTranslation(key)) {
                    const existingText = (element.textContent || '').trim();
                    const translation = this.t(key, params, existingText);

                    // Do not replace a correct PHP-rendered count with an
                    // unresolved template such as "{count} products".
                    if (!/\{[A-Za-z0-9_]+\}/.test(translation)) {
                        if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
                            if (element.hasAttribute('placeholder')) {
                                element.placeholder = translation;
                            } else {
                                element.value = translation;
                            }
                        } else {
                            const directTextNodes = Array.from(element.childNodes)
                                .filter(node => node.nodeType === Node.TEXT_NODE);
                            const meaningfulTextNode = directTextNodes.find(node => node.nodeValue.trim() !== '');
                            const hasElementChild = Array.from(element.childNodes)
                                .some(node => node.nodeType === Node.ELEMENT_NODE);

                            if (hasElementChild) {
                                if (meaningfulTextNode) {
                                    meaningfulTextNode.nodeValue = ' ' + translation;
                                    directTextNodes.forEach(node => {
                                        if (node !== meaningfulTextNode && node.nodeValue.trim() !== '') {
                                            node.nodeValue = '';
                                        }
                                    });
                                } else {
                                    element.appendChild(document.createTextNode(' ' + translation));
                                }
                            } else if (element.textContent !== translation) {
                                element.textContent = translation;
                            }
                        }
                    }
                }
            }

            if (element.hasAttribute('data-lang-placeholder')) {
                const key = element.getAttribute('data-lang-placeholder');
                if (this.hasTranslation(key)) {
                    const translation = this.t(key, params, element.placeholder || '');
                    if (!/\{[A-Za-z0-9_]+\}/.test(translation)) {
                        element.placeholder = translation;
                    }
                }
            }

            if (element.hasAttribute('data-lang-title')) {
                const key = element.getAttribute('data-lang-title');
                if (this.hasTranslation(key)) {
                    const translation = this.t(key, params, element.title || '');
                    if (!/\{[A-Za-z0-9_]+\}/.test(translation)) {
                        element.title = translation;
                    }
                }
            }
        });

        document.documentElement.lang = this.current;
    },

    // Update language switcher button UI
    updateSwitcherUI: function () {
        const switchers = document.querySelectorAll('.lang-switcher, #langSwitcher');
        switchers.forEach(switcher => {
            const langInfo = this.languages[this.current];
            const otherLang = this.current === 'en' ? 'th' : 'en';
            const otherLangInfo = this.languages[otherLang];

            const textSpan = switcher.querySelector('.lang-text');
            if (textSpan) {
                textSpan.textContent = `${langInfo.flag} ${langInfo.name}`;
            } else {
                switcher.innerHTML = `
                    <span class="lang-text text-xs">${langInfo.flag} ${langInfo.name}</span>
                    <i class="bi bi-translate ml-1.5 text-xs"></i>
                `;
            }
            switcher.title = `Switch to ${otherLangInfo.name}`;
        });
    },

    // Create language switcher HTML
    createSwitcher: function (position = 'fixed') {
        const posClass = position === 'fixed'
            ? 'fixed top-4 right-4 z-50'
            : 'relative';

        return `
            <button id="langSwitcher" 
                    onclick="Lang.toggle()" 
                    class="${posClass} glass border border-white/10 rounded-lg px-3 py-2 text-gray-300 hover:text-white hover:border-accent/50 transition-all duration-150 flex items-center gap-1 backdrop-blur-md bg-panel/50 hover:scale-105 lang-switcher">
                <span class="lang-text text-xs">${this.languages[this.current].flag} ${this.languages[this.current].name}</span>
                <i class="bi bi-translate ml-1.5 text-xs"></i>
            </button>
        `;
    },

    // Add translation key dynamically
    addTranslation: function (key, en, th) {
        this.translations[key] = { en, th };
    },

    // Alias for updatePage for backward compatibility
    apply: function() {
        this.updatePage();
    },

    // Robust Copy to Clipboard with Fallback & Callback support
    copy: function(text, callback) {
        if (!text) return;
        
        const showSwal = !callback;
        
        const performCopy = () => {
            if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                return navigator.clipboard.writeText(text);
            } else {
                return new Promise((res, rej) => {
                    try {
                        const textArea = document.createElement("textarea");
                        textArea.value = text;
                        textArea.style.position = "fixed";
                        textArea.style.left = "-9999px";
                        textArea.style.top = "0";
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        const success = document.execCommand('copy');
                        textArea.remove();
                        success ? res() : rej(new Error('execCommand failed'));
                    } catch (e) {
                        rej(e);
                    }
                });
            }
        };

        performCopy()
            .then(() => {
                if (showSwal && typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: this.t('common.copied') || 'Copied!',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false,
                        background: '#141418',
                        color: '#fff'
                    });
                }
                if (callback) callback(true);
            })
            .catch(err => {
                console.error('Copy failed:', err);
                if (showSwal && typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: this.t('common.copy_failed'),
                        text: this.t('common.copy_manually'),
                        icon: 'error',
                        background: '#141418',
                        color: '#fff'
                    });
                }
                if (callback) callback(false);
            });
    }
};

// Top-level const declarations are not properties of window. Export explicitly
// so dynamically loaded page scripts can safely use window.Lang.
window.Lang = Lang;

// Auto-initialize (works even if script loads after DOMContentLoaded)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        Lang.init();
    });
} else {
    Lang.init();
}
