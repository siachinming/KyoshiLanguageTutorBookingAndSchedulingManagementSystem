<?php
// promotion_banner.php
function showPromotionBanner($conn, $userID) {
    // Check if user is eligible for first-time promotion
    $checkUser = $conn->prepare("
        SELECT first_promo_applied, is_first_purchase 
        FROM users 
        WHERE id = ? AND role = 'student' 
        AND first_promo_applied = 0 AND is_first_purchase = 1
    ");
    $checkUser->bind_param("i", $userID);
    $checkUser->execute();
    $user = $checkUser->get_result()->fetch_assoc();
    
    if (!$user) {
        return ''; // User not eligible, don't show banner
    }
    
    // Check if user has any verified payments (already bought before)
    $checkPayments = $conn->prepare("
        SELECT COUNT(*) as count FROM payments 
        WHERE student_id = ? AND status = 'verified'
    ");
    $checkPayments->bind_param("i", $userID);
    $checkPayments->execute();
    $paymentCount = $checkPayments->get_result()->fetch_assoc()['count'];
    
    if ($paymentCount > 0) {
        // User already made a purchase, update database
        $updateUser = $conn->prepare("UPDATE users SET is_first_purchase = 0 WHERE id = ?");
        $updateUser->bind_param("i", $userID);
        $updateUser->execute();
        return ''; // Don't show banner
    }
    
    // Get the promotion details from database
    $promoStmt = $conn->prepare("
        SELECT * FROM promotions 
        WHERE code = 'FIRST20' AND is_active = 1 
        AND (valid_to IS NULL OR valid_to >= CURDATE())
    ");
    $promoStmt->execute();
    $promotion = $promoStmt->get_result()->fetch_assoc();
    
    // Return the banner HTML
    return '
    <div id="promoBanner" style="position: relative; margin-bottom: 30px; cursor: pointer;" onclick="handleBannerClick()">
        <img src="../assets/img/KYOSHI.png" 
             alt="First Session 20% OFF" 
             style="width: 100%; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        
        <!-- Close button -->
        <button onclick="event.stopPropagation(); closePromoBanner()" style="
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0,0,0,0.5);
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        ">&times;</button>
    </div>
    
    <script>
    function closePromoBanner() {
        document.getElementById("promoBanner").style.display = "none";
        localStorage.setItem("promoBannerClosed", "true");
    }
    
    function handleBannerClick() {
        window.location.href = "find_language.php?promo=FIRST20";
    }
    
    // Check if user already closed the banner before
    if (localStorage.getItem("promoBannerClosed") === "true") {
        document.getElementById("promoBanner").style.display = "none";
    }
    </script>
    ';
}
?>