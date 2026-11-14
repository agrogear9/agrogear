<?php
require_once __DIR__ . '/order-pdf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['delivery_csrf_token'])) {
    $_SESSION['delivery_csrf_token'] = bin2hex(random_bytes(32));
}

function productImage(string $category, string $filename): string
{
    return 'agricultural%20tools%20images/' . rawurlencode($category) . '/' . rawurlencode($filename);
}

$products = [
    [
        'id' => 'pruning-shear',
        'name' => 'Stainless Steel Pruning Shear',
        'price' => 1400,
        'priceRange' => false,
        'image' => productImage('1. Hand Tools & Manual Equipment', 'Bypass Secateurs, precision cutting(ksh 1400).webp'),
        'alt' => 'Stainless steel pruning shears',
    ],
    [
        'id' => 'irrigation-controller',
        'name' => 'Drip Irrigation Controller',
        'price' => 65000,
        'priceRange' => false,
        'image' => productImage('4. Irrigation & Water Management', 'Controller box + pump (without panels) Ksh 65,000 - 95,000.jpg'),
        'alt' => 'Drip irrigation controller and pump',
    ],
    [
        'id' => 'electric-sprayer',
        'name' => 'Cordless Electric Sprayer (16L)',
        'price' => 3200,
        'priceRange' => false,
        'image' => productImage('5. Crop Protection & Precision Sensors', '16L Knapsack Sprayer - KENSPLASTIC Brand - Smaller Tank (ksh 3,200).jpg'),
        'alt' => '16 litre knapsack sprayer',
    ],
    [
        'id' => 'soil-moisture-sensor',
        'name' => 'Precision Soil Moisture Sensor',
        'price' => 6800,
        'priceRange' => false,
        'image' => productImage('5. Crop Protection & Precision Sensors', '6-in-1 Soil Tester for Soil Moisture, pH, EC, Light, Air Humidity, Temperature(ksh 6,800).webp'),
        'alt' => 'Digital soil moisture tester',
    ],
];

$imageRoot = __DIR__ . DIRECTORY_SEPARATOR . 'agricultural tools images';
$categoryDirectories = [];
foreach (new DirectoryIterator($imageRoot) as $entry) {
    if ($entry->isDir() && !$entry->isDot()) {
        $categoryDirectories[] = $entry->getFilename();
    }
}
natcasesort($categoryDirectories);

$categories = [];
$catalogProducts = [];
$supportedImageTypes = ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'];

foreach (array_values($categoryDirectories) as $index => $categoryName) {
    $categoryId = 'category-' . ($index + 1);
    $categoryProducts = [];
    $categoryPath = $imageRoot . DIRECTORY_SEPARATOR . $categoryName;

    foreach (new DirectoryIterator($categoryPath) as $entry) {
        if (!$entry->isFile() || !in_array(strtolower($entry->getExtension()), $supportedImageTypes, true)) {
            continue;
        }

        $filename = $entry->getFilename();
        $price = null;
        $priceLabel = 'Price on request';
        $priceRange = false;
        if (preg_match('/ksh\s*([\d,]+)(?:\s*[-–]\s*([\d,]+))?/i', $filename, $priceMatch)) {
            $price = (int) str_replace(',', '', $priceMatch[1]);
            $priceLabel = 'KSh ' . number_format($price);
            if (!empty($priceMatch[2])) {
                $priceRange = true;
                $priceLabel .= '–' . number_format((int) str_replace(',', '', $priceMatch[2]));
            }
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/\s*\(?\s*ksh\s*[\d,]+.*$/i', '', $name);
        $name = trim($name, " \t\n\r\0\x0B-–,.");
        $product = [
            'id' => 'product-' . substr(hash('sha256', $categoryName . '/' . $filename), 0, 16),
            'name' => $name !== '' ? $name : pathinfo($filename, PATHINFO_FILENAME),
            'price' => $price,
            'priceLabel' => $priceLabel,
            'priceRange' => $priceRange,
            'image' => productImage($categoryName, $filename),
            'alt' => $name !== '' ? $name : pathinfo($filename, PATHINFO_FILENAME),
            'categoryId' => $categoryId,
        ];
        $categoryProducts[] = $product;
        $catalogProducts[] = $product;
    }

    usort($categoryProducts, static function (array $first, array $second): int {
        return strnatcasecmp($first['name'], $second['name']);
    });

    $categories[] = [
        'id' => $categoryId,
        'name' => $categoryName,
        'products' => $categoryProducts,
    ];
}

$catalogById = array_column(array_merge($products, $catalogProducts), null, 'id');
$deliveryFields = [
    'buyer_name' => '',
    'buyer_email' => '',
    'buyer_phone' => '',
    'county' => '',
    'town' => '',
    'street' => '',
    'estate_building' => '',
    'landmark' => '',
    'delivery_notes' => '',
    'mpesa_reference' => '',
];
$deliveryErrors = [];
$deliveryMessage = '';
$deliverySuccess = false;
$deliveryPaymentCompleted = false;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['delivery_success_message'])) {
    $deliverySuccess = true;
    $deliveryMessage = $_SESSION['delivery_success_message'];
    unset($_SESSION['delivery_success_message']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['delivery_form_flash'])) {
    $formFlash = $_SESSION['delivery_form_flash'];
    unset($_SESSION['delivery_form_flash']);
    $deliveryFields = $formFlash['fields'];
    $deliveryErrors = $formFlash['errors'];
    $deliveryMessage = $formFlash['message'];
    $deliveryPaymentCompleted = $formFlash['payment_completed'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'delivery_order') {
    foreach ($deliveryFields as $field => $defaultValue) {
        $value = $_POST[$field] ?? '';
        $deliveryFields[$field] = is_string($value) ? trim($value) : $defaultValue;
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['delivery_csrf_token'], $submittedToken)) {
        $deliveryErrors[] = 'Your form session expired. Refresh the page and submit your delivery details again.';
    }

    if (!empty($_POST['website'])) {
        $deliveryErrors[] = 'The form could not be submitted. Please try again.';
    }

    if (mb_strlen($deliveryFields['buyer_name']) < 2 || mb_strlen($deliveryFields['buyer_name']) > 120) {
        $deliveryErrors[] = 'Enter your name (2 to 120 characters).';
    }
    if (
        mb_strlen($deliveryFields['buyer_email']) > 254
        || !filter_var($deliveryFields['buyer_email'], FILTER_VALIDATE_EMAIL)
    ) {
        $deliveryErrors[] = 'Enter a valid email address.';
    }
    if (!preg_match('/^\+?[0-9\s()-]{7,20}$/', $deliveryFields['buyer_phone'])) {
        $deliveryErrors[] = 'Enter a valid phone number.';
    }
    foreach (['county' => 'County', 'town' => 'Town or area', 'street' => 'Street or road'] as $field => $label) {
        if ($deliveryFields[$field] === '' || mb_strlen($deliveryFields[$field]) > 120) {
            $deliveryErrors[] = $label . ' is required and must be 120 characters or fewer.';
        }
    }
    foreach (['estate_building' => 'Estate or building', 'landmark' => 'Nearby landmark', 'delivery_notes' => 'Delivery notes'] as $field => $label) {
        if (mb_strlen($deliveryFields[$field]) > 250) {
            $deliveryErrors[] = $label . ' must be 250 characters or fewer.';
        }
    }
    if (!preg_match('/^[A-Za-z0-9]{8,20}$/', $deliveryFields['mpesa_reference'])) {
        $deliveryErrors[] = 'Enter the M-Pesa confirmation code from your payment message.';
    }
    if (($_POST['payment_completed'] ?? '') !== '1') {
        $deliveryErrors[] = 'Confirm that you have completed the M-Pesa payment before submitting delivery details.';
    }

    $submittedCartJson = $_POST['order_cart'] ?? '';
    $submittedCart = is_string($submittedCartJson) && strlen($submittedCartJson) <= 16000
        ? json_decode($submittedCartJson, true)
        : null;
    $orderItems = [];
    $orderTotal = 0;
    if (!is_array($submittedCart) || $submittedCart === [] || count($submittedCart) > 100) {
        $deliveryErrors[] = 'Your cart is empty or invalid. Add fixed-price equipment to your cart and try again.';
    } else {
        foreach ($submittedCart as $productId => $quantity) {
            if (
                !is_string($productId)
                || !isset($catalogById[$productId])
                || $catalogById[$productId]['price'] === null
                || $catalogById[$productId]['priceRange']
                || !is_int($quantity)
                || $quantity < 1
                || $quantity > 50
            ) {
                $deliveryErrors[] = 'Your cart contains an item with an invalid price or quantity. Review your cart and try again.';
                break;
            }

            $product = $catalogById[$productId];
            $lineTotal = $product['price'] * $quantity;
            $orderTotal += $lineTotal;
            $orderItems[] = [
                'name' => $product['name'],
                'quantity' => $quantity,
                'unit_price' => $product['price'],
                'line_total' => $lineTotal,
            ];
        }
    }

    if ($deliveryErrors === []) {
        $orderNumber = 'AG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        try {
            $mailConfig = require __DIR__ . '/mail-config.php';
        } catch (RuntimeException $exception) {
            error_log('Agrogear mail configuration error: ' . $exception->getMessage());
            $deliveryMessage = 'Order email is not configured yet. Please call or WhatsApp the store for delivery assistance.';
        }

        if (isset($mailConfig)) {
            try {
                $orderPdf = createAgrogearOrderPdf([
                    'number' => $orderNumber,
                    'date' => date('j F Y, g:i A'),
                    'buyer_name' => $deliveryFields['buyer_name'],
                    'buyer_email' => $deliveryFields['buyer_email'],
                    'buyer_phone' => $deliveryFields['buyer_phone'],
                    'mpesa_reference' => $deliveryFields['mpesa_reference'],
                    'total' => $orderTotal,
                    'county' => $deliveryFields['county'],
                    'town' => $deliveryFields['town'],
                    'street' => $deliveryFields['street'],
                    'estate_building' => $deliveryFields['estate_building'],
                    'landmark' => $deliveryFields['landmark'],
                    'delivery_notes' => $deliveryFields['delivery_notes'],
                    'items' => $orderItems,
                ]);
            } catch (RuntimeException $exception) {
                error_log('Agrogear order PDF generation failed: ' . $exception->getMessage());
                $deliveryMessage = 'We could not prepare the order PDF. Please call or WhatsApp the store for delivery assistance.';
            }
        }

        if (isset($mailConfig, $orderPdf)) {
            $orderEmailBody = 'Hello ' . $deliveryFields['buyer_name'] . ",\n\n"
                . 'We have received your Agrogear order ' . $orderNumber . '.'
                . "\nOrder total: KSh " . number_format($orderTotal)
                . "\nPlease find your order details attached. The store will verify your M-Pesa payment manually."
                . "\n\nAgrogear";
            $buyerEmailSent = false;
            $adminEmailSent = false;

            try {
                sendAgrogearEmail(
                    $mailConfig,
                    $deliveryFields['buyer_email'],
                    'Agrogear order confirmation ' . $orderNumber,
                    $orderEmailBody,
                    $orderPdf
                );
                $buyerEmailSent = true;
            } catch (PHPMailer\PHPMailer\Exception $exception) {
                error_log('Agrogear buyer email failed for ' . $orderNumber . ' to ' . $deliveryFields['buyer_email'] . ': ' . $exception->getMessage());
            }

            try {
                sendAgrogearEmail(
                    $mailConfig,
                    $mailConfig['order_email'],
                    'Agrogear delivery order ' . $orderNumber,
                    $orderEmailBody,
                    $orderPdf
                );
                $adminEmailSent = true;
            } catch (PHPMailer\PHPMailer\Exception $exception) {
                error_log('Agrogear admin order email failed for ' . $orderNumber . ' to ' . $mailConfig['order_email'] . ': ' . $exception->getMessage());
            }

            if ($buyerEmailSent && $adminEmailSent) {
                $deliverySuccess = true;
                $deliveryMessage = 'Your order confirmation was submitted to the email address you entered, and a copy was submitted to the store. Check your inbox and spam folder for the confirmation.';
                $_SESSION['delivery_csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['delivery_success_message'] = $deliveryMessage;
                header('Location: ' . $_SERVER['SCRIPT_NAME'] . '#delivery-details', true, 303);
                exit;
            }
            if ($adminEmailSent) {
                $deliveryMessage = 'The store received your order, but the buyer email could not be submitted to ' . $deliveryFields['buyer_email'] . '. Check the email address or contact us with order ' . $orderNumber . '.';
            } elseif ($buyerEmailSent) {
                $deliveryMessage = 'Your confirmation was submitted to ' . $deliveryFields['buyer_email'] . ', but the store could not be notified. Please contact us with order ' . $orderNumber . '.';
            } else {
                $deliveryMessage = 'Neither order email could be submitted. Please call or WhatsApp us with order ' . $orderNumber . '.';
            }
        }
    }

    $_SESSION['delivery_form_flash'] = [
        'fields' => $deliveryFields,
        'errors' => $deliveryErrors,
        'message' => $deliveryMessage,
        'payment_completed' => ($_POST['payment_completed'] ?? '') === '1',
    ];
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '#delivery-details', true, 303);
    exit;
}

$tractorImage = productImage('2. Land Preparation & Tillage Machinery', '10HP JD10L JIADI Walking Diesel tractor (ksh 238,995).jpg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#214d37">
    <meta name="description" content="Shop quality agricultural tools, irrigation equipment and smart farming technology at Agrogear.">
    <title>Agrogear | Built for the Modern Harvest</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="site-shell">
        <header class="site-header">
            <div class="nav-wrap">
                <a class="brand" href="#home" aria-label="Agrogear home">
                    <span class="brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 36 36" fill="none">
                            <path d="M18 29V15m0 7c-7 0-11-4-11-10 7 0 11 4 11 10Zm0-4c0-7 4-11 11-11 0 7-4 11-11 11Z" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M10 30h16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <span>AGROGEAR</span>
                </a>

                <nav class="main-nav" id="main-nav" aria-label="Main navigation">
                    <a href="#home">Home</a>
                    <div class="equipment-menu" id="equipment-menu">
                        <button class="equipment-toggle" id="equipment-toggle" type="button" aria-expanded="false" aria-controls="category-menu">
                            Equipments <span aria-hidden="true">⌄</span>
                        </button>
                        <div class="category-menu" id="category-menu" aria-label="Equipment categories" hidden>
                            <?php foreach ($categories as $category): ?>
                                <button class="category-link" type="button" data-category-id="<?= htmlspecialchars($category['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a href="#contact">Contacts</a>
                    <a href="#delivery-details">Delivery Details</a>
                    <a href="#about">About Us</a>
                    <a href="#payment-instructions" id="how-to-pay-link">Payment Details</a>
                </nav>

                <div class="header-actions">
                    <button class="cart-button" id="cart-button" type="button" aria-label="Shopping cart, 0 items">
                        <svg class="cart-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3 4h2l2.2 11.1a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 1.9-1.4L22 8H6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="10" cy="20" r="1.4" fill="currentColor"/>
                            <circle cx="18" cy="20" r="1.4" fill="currentColor"/>
                        </svg>
                        <span>Cart</span>
                        <span class="cart-count" id="cart-count" aria-live="polite">0</span>
                    </button>
                    <button class="menu-toggle" id="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="main-nav">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <main id="home">
            <section class="hero" aria-labelledby="hero-title">
                <img class="hero-image" src="<?= htmlspecialchars($tractorImage, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true">
                <div class="hero-content">
                    <div class="hero-copy">
                        <h1 id="hero-title">Built for the<br>Modern Harvest</h1>
                        <p>Premium Tools &amp; Smart Technology for Sustainable Agriculture</p>
                        <a class="primary-link" href="#products">Shop Equipment</a>
                    </div>
                </div>
            </section>

            <section class="featured" id="products" aria-labelledby="products-heading">
                <h2 class="section-heading" id="products-heading">Shop by Category</h2>
                <div class="product-grid" id="product-grid">
                    <?php foreach ($products as $product): ?>
                        <article class="product-card">
                            <div class="product-image-wrap">
                                <img class="product-image" src="<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['alt'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            </div>
                            <h3 class="product-name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="product-price">KSh <?= number_format($product['price']) ?></p>
                            <button class="add-button" type="button" data-product-id="<?= htmlspecialchars($product['id'], ENT_QUOTES, 'UTF-8') ?>">Add to Cart</button>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="category-catalog" id="equipment-catalog" aria-labelledby="catalog-heading" hidden>
                <h2 class="section-heading" id="catalog-heading">Equipment</h2>
                <p class="catalog-description" id="catalog-description"></p>
                <div class="product-grid catalog-grid" id="catalog-grid">
                    <?php foreach ($categories as $category): ?>
                        <?php foreach ($category['products'] as $product): ?>
                            <article class="product-card catalog-card" data-category-id="<?= htmlspecialchars($category['id'], ENT_QUOTES, 'UTF-8') ?>" hidden>
                                <div class="product-image-wrap">
                                    <img class="product-image" src="<?= htmlspecialchars($product['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['alt'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                                </div>
                                <h3 class="product-name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="product-price"><?= htmlspecialchars($product['priceLabel'], ENT_QUOTES, 'UTF-8') ?></p>
                                <button class="add-button" type="button" data-product-id="<?= htmlspecialchars($product['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $product['price'] === null || $product['priceRange'] ? 'disabled' : '' ?>>
                                    <?= $product['priceRange'] ? 'Price Varies — Contact Us' : ($product['price'] === null ? 'Price on Request' : 'Add to Cart') ?>
                                </button>
                            </article>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="delivery-section" id="delivery-details" aria-labelledby="delivery-heading">
                <h2 class="section-heading" id="delivery-heading">Delivery Details</h2>
                <p class="delivery-intro">After paying by M-Pesa, complete the form so we can arrange delivery. Enter your M-Pesa confirmation code and delivery address.</p>
                <aside class="delivery-estimate" role="note" aria-label="Delivery estimate">
                    <span class="delivery-estimate-icon" aria-hidden="true">3-5</span>
                    <span><strong>Delivery usually takes 3-5 days</strong><br>after payment and delivery details are received. Timing may vary depending on your location.</span>
                </aside>
                <div class="delivery-access-gate" id="delivery-access-gate">
                    <h3>Get payment details first</h3>
                    <p>Call or WhatsApp us to confirm your order and receive payment details. Once you have them, continue to enter your delivery information.</p>
                    <div class="payment-contact-actions">
                        <a class="payment-contact-button" href="tel:+254781998896">Call +254 781 998896</a>
                        <a class="payment-contact-button payment-contact-button-whatsapp" href="https://wa.me/254781998896" target="_blank" rel="noopener noreferrer">WhatsApp +254 781 998896</a>
                    </div>
                    <button class="delivery-form-button" id="delivery-access-button" type="button">I have received payment details</button>
                </div>

                <?php if ($deliverySuccess): ?>
                    <p class="form-message form-message-success" role="status"><?= htmlspecialchars($deliveryMessage, ENT_QUOTES, 'UTF-8') ?></p>
                <?php elseif ($deliveryMessage !== '' || $deliveryErrors !== []): ?>
                    <div class="form-message form-message-error" role="alert">
                        <?php if ($deliveryMessage !== ''): ?>
                            <p><?= htmlspecialchars($deliveryMessage, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if ($deliveryErrors !== []): ?>
                            <ul>
                                <?php foreach ($deliveryErrors as $error): ?>
                                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form class="delivery-form" id="delivery-form" method="post" action="#delivery-details" hidden>
                    <input type="hidden" name="form_type" value="delivery_order">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['delivery_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="order_cart" id="order-cart" value="">
                    <div class="form-honey" aria-hidden="true">
                        <label for="website">Leave this field empty</label>
                        <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="delivery-order-summary">
                        <h3>Items in your cart</h3>
                        <p id="delivery-cart-summary">Your cart is empty. Add products before submitting delivery details.</p>
                    </div>

                    <div class="delivery-fields">
                        <label class="form-field">
                            <span>Buyer full name *</span>
                            <input name="buyer_name" type="text" required minlength="2" maxlength="120" autocomplete="name" value="<?= htmlspecialchars($deliveryFields['buyer_name'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>Email for order confirmation *</span>
                            <input name="buyer_email" type="email" required maxlength="254" autocomplete="email" value="<?= htmlspecialchars($deliveryFields['buyer_email'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>Phone number *</span>
                            <input name="buyer_phone" type="tel" required maxlength="20" autocomplete="tel" placeholder="e.g. 07xx xxx xxx" value="<?= htmlspecialchars($deliveryFields['buyer_phone'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>County *</span>
                            <input name="county" type="text" required maxlength="120" autocomplete="address-level1" value="<?= htmlspecialchars($deliveryFields['county'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>Town or area *</span>
                            <input name="town" type="text" required maxlength="120" autocomplete="address-level2" value="<?= htmlspecialchars($deliveryFields['town'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>Street or road *</span>
                            <input name="street" type="text" required maxlength="120" autocomplete="street-address" value="<?= htmlspecialchars($deliveryFields['street'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>Estate or building</span>
                            <input name="estate_building" type="text" maxlength="250" value="<?= htmlspecialchars($deliveryFields['estate_building'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>Nearby landmark</span>
                            <input name="landmark" type="text" maxlength="250" value="<?= htmlspecialchars($deliveryFields['landmark'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field">
                            <span>M-Pesa confirmation code *</span>
                            <input name="mpesa_reference" type="text" required minlength="8" maxlength="20" pattern="[A-Za-z0-9]{8,20}" autocomplete="off" value="<?= htmlspecialchars($deliveryFields['mpesa_reference'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="form-field form-field-wide">
                            <span>Additional delivery directions</span>
                            <textarea name="delivery_notes" rows="3" maxlength="250"><?= htmlspecialchars($deliveryFields['delivery_notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </label>
                    </div>

                    <label class="payment-confirmation">
                        <input type="checkbox" name="payment_completed" value="1" required <?= $deliveryPaymentCompleted ? 'checked' : '' ?>>
                        <span>I have completed the M-Pesa payment and entered the confirmation code above. I understand the store will verify the payment manually.</span>
                    </label>
                    <button class="delivery-submit" id="delivery-submit" type="submit" disabled>Send delivery details</button>
                    <p class="delivery-privacy">Your order and delivery details will be emailed to the store and the email address you provide.</p>
                </form>
            </section>

            <section class="benefits" aria-label="Why shop with Agrogear">
                <div class="benefit">
                    <span class="benefit-icon" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M16 3 26 7v8c0 6.4-4.3 11-10 14-5.7-3-10-7.6-10-14V7l10-4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="m11 16 3.3 3.3L21 12.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <div><strong>2-Year Field Warranty</strong><span>Quality you can count on</span></div>
                </div>
                <div class="benefit">
                    <span class="benefit-icon" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M3 10h17v14H3zM20 14h5l4 5v5h-9z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <circle cx="9" cy="25" r="2.5" fill="#f7f8f7" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="24" cy="25" r="2.5" fill="#f7f8f7" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M6 14h8m-8 4h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <div><strong>Fast Farm Delivery</strong><span>From our store to your farm</span></div>
                </div>
                <div class="benefit">
                    <span class="benefit-icon" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none">
                            <path d="M7 17v-2a9 9 0 0 1 18 0v2" stroke="currentColor" stroke-width="1.8"/>
                            <rect x="4" y="16" width="6" height="9" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <rect x="22" y="16" width="6" height="9" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M25 25c-1 2-3 3-7 3h-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <circle cx="14" cy="28" r="1.5" fill="currentColor"/>
                        </svg>
                    </span>
                    <div><strong>Expert Support</strong><span>Here to help you grow</span></div>
                </div>
            </section>
        </main>

        <footer class="site-footer" id="about">
            <p class="footer-tagline">Growing better, together.</p>
            <section class="contact-details" id="contact" aria-labelledby="contact-heading">
                <h2 id="contact-heading">Contact Us</h2>
                <div class="contact-links">
                    <a class="contact-link" href="tel:+254781998896" aria-label="Call +254 781 998896">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7.2 3.5h2.5l1.2 4.1-1.8 1.7a15 15 0 0 0 5.6 5.6l1.7-1.8 4.1 1.2v2.5c0 1.1-.9 2-2 2C10 18.8 5.2 14 5.2 5.5c0-1.1.9-2 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>+254 781 998896</span>
                    </a>
                    <a class="contact-link" href="https://wa.me/254781998896" target="_blank" rel="noopener noreferrer" aria-label="Message us on WhatsApp at +254 781 998896">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M20 11.6a8 8 0 0 1-11.8 7l-4.2 1.1 1.1-4.1A8 8 0 1 1 20 11.6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                            <path d="M9 8.5c.3-.5.6-.5.9-.5.2 0 .4 0 .6.5l.8 1.6c.1.3.1.5-.1.7l-.6.7a6 6 0 0 0 2.8 2.8l.7-.6c.2-.2.5-.2.7-.1l1.6.8c.4.2.5.4.5.6 0 .3 0 .6-.5.9-.5.4-1.1.6-1.8.4-1.2-.3-2.8-1-4.3-2.5s-2.2-3.1-2.5-4.3c-.2-.7 0-1.3.4-1.8Z" fill="currentColor"/>
                        </svg>
                        <span>WhatsApp +254 781 998896</span>
                    </a>
                    <a class="contact-link" id="contact-email" href="mailto:agrogear9@gmail.com" aria-label="Email agrogear9@gmail.com">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>agrogear9@gmail.com</span>
                    </a>
                </div>
            </section>
            <p class="footer-phone-link"><a href="home.php">Open Agrogear on your phone</a></p>
        </footer>
    </div>

    <dialog class="cart-dialog" id="cart-dialog" aria-labelledby="cart-title">
        <div class="cart-panel">
            <header class="cart-header">
                <h2 id="cart-title">Your Cart</h2>
                <button class="cart-close" id="cart-close" type="button" aria-label="Close cart">&times;</button>
            </header>
            <div class="cart-items" id="cart-items"></div>
            <div class="cart-footer">
                <div class="cart-total-row"><span>Total</span><strong id="cart-total">KSh 0</strong></div>
                <button class="checkout-button" id="checkout-button" type="button" disabled>Get payment details</button>
                <section class="payment-instructions" id="payment-instructions" aria-labelledby="payment-title" hidden>
                    <h3 id="payment-title">Contact us for payment details</h3>
                    <p>Call or WhatsApp us first to confirm your order and receive the payment details. Please do not send payment until you have spoken with us.</p>
                    <p class="payment-contact-reminder">
                        Contact Agrogear to get your payment instructions:
                    </p>
                    <div class="payment-contact-actions">
                        <a class="payment-contact-button" href="tel:+254781998896">Call +254 781 998896</a>
                        <a class="payment-contact-button payment-contact-button-whatsapp" href="https://wa.me/254781998896" target="_blank" rel="noopener noreferrer">WhatsApp +254 781 998896</a>
                    </div>
                    <button class="delivery-form-button" id="payment-details-received" type="button">I have received payment details</button>
                    <p class="payment-delivery-prompt" id="payment-delivery-prompt" hidden>
                        After receiving the payment details and completing your payment, continue to provide your delivery details and M-Pesa confirmation code.
                        <a class="delivery-form-button" href="#delivery-form" id="delivery-form-link">Fill Your Delivery Details Here</a>
                    </p>
                </section>
            </div>
        </div>
    </dialog>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
    <script>
        const productCatalog = <?= json_encode(array_column(array_merge($products, $catalogProducts), null, 'id'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const deliverySubmissionSuccessful = <?= json_encode($deliverySuccess) ?>;
        const cartCount = document.getElementById('cart-count');
        const cartButton = document.getElementById('cart-button');
        const cartDialog = document.getElementById('cart-dialog');
        const cartItemsElement = document.getElementById('cart-items');
        const cartTotalElement = document.getElementById('cart-total');
        const checkoutButton = document.getElementById('checkout-button');
        const paymentInstructions = document.getElementById('payment-instructions');
        const paymentDeliveryPrompt = document.getElementById('payment-delivery-prompt');
        const deliveryForm = document.getElementById('delivery-form');
        const deliveryAccessGate = document.getElementById('delivery-access-gate');
        const deliveryAccessButton = document.getElementById('delivery-access-button');
        const paymentDetailsReceivedButton = document.getElementById('payment-details-received');
        const menuToggle = document.getElementById('menu-toggle');
        const mainNav = document.getElementById('main-nav');
        const equipmentMenu = document.getElementById('equipment-menu');
        const equipmentToggle = document.getElementById('equipment-toggle');
        const categoryMenu = document.getElementById('category-menu');
        const equipmentCatalog = document.getElementById('equipment-catalog');
        const catalogHeading = document.getElementById('catalog-heading');
        const catalogDescription = document.getElementById('catalog-description');
        const catalogCards = Array.from(document.querySelectorAll('.catalog-card'));
        const categoryNames = <?= json_encode(array_column($categories, 'name', 'id'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const orderCartInput = document.getElementById('order-cart');
        const deliveryCartSummary = document.getElementById('delivery-cart-summary');
        const deliverySubmit = document.getElementById('delivery-submit');
        const toast = document.getElementById('toast');
        const deliveryAccessKey = 'agrogear-payment-details-received';
        let cart = {};
        let toastTimer;

        function formatPrice(amount) {
            return `KSh ${new Intl.NumberFormat('en-KE').format(amount)}`;
        }

        function showToast(message) {
            toast.textContent = message;
            toast.classList.add('show');
            window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(() => toast.classList.remove('show'), 2600);
        }

        function saveCart() {
            try {
                localStorage.setItem('agrogear-cart', JSON.stringify(cart));
            } catch (error) {
                showToast('Cart could not be saved on this device. It will be cleared when you leave this page.');
            }
        }

        function loadCart() {
            try {
                const savedCart = localStorage.getItem('agrogear-cart');
                if (!savedCart) return;

                const parsedCart = JSON.parse(savedCart);
                if (!parsedCart || typeof parsedCart !== 'object' || Array.isArray(parsedCart)) return;

                Object.entries(parsedCart).forEach(([productId, quantity]) => {
                    if (productCatalog[productId] && productCatalog[productId].price !== null && !productCatalog[productId].priceRange && Number.isSafeInteger(quantity) && quantity > 0) {
                        cart[productId] = quantity;
                    }
                });
            } catch (error) {
                showToast('Saved cart data could not be read. You can start a new cart.');
            }
        }

        function cartQuantity() {
            return Object.values(cart).reduce((total, quantity) => total + quantity, 0);
        }

        function cartTotal() {
            return Object.entries(cart).reduce((total, [productId, quantity]) => {
                return total + productCatalog[productId].price * quantity;
            }, 0);
        }

        function updateDeliveryForm() {
            const orderLines = Object.entries(cart).map(([productId, quantity]) => {
                const product = productCatalog[productId];
                return `${product.name} × ${quantity} — ${formatPrice(product.price * quantity)}`;
            });

            orderCartInput.value = JSON.stringify(cart);
            deliveryCartSummary.textContent = orderLines.length > 0
                ? `${orderLines.join('\n')}\nTotal: ${formatPrice(cartTotal())}`
                : 'Your cart is empty. Add products before submitting delivery details.';
            deliverySubmit.disabled = orderLines.length === 0;
        }

        function renderCart() {
            const quantity = cartQuantity();
            const total = cartTotal();
            cartItemsElement.replaceChildren();
            cartCount.textContent = String(quantity);
            cartCount.classList.toggle('visible', quantity > 0);
            cartButton.setAttribute('aria-label', `Shopping cart, ${quantity} items`);
            cartTotalElement.textContent = formatPrice(total);
            checkoutButton.disabled = quantity === 0;
            updateDeliveryForm();

            if (quantity === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'cart-empty';
                emptyMessage.textContent = 'Your cart is empty. Add equipment to get started.';
                cartItemsElement.append(emptyMessage);
                paymentInstructions.hidden = true;
                checkoutButton.textContent = 'Get payment details';
                return;
            }

            Object.entries(cart).forEach(([productId, itemQuantity]) => {
                const product = productCatalog[productId];
                const line = document.createElement('article');
                line.className = 'cart-line';

                const image = document.createElement('img');
                image.className = 'cart-line-image';
                image.src = product.image;
                image.alt = product.alt;

                const details = document.createElement('div');
                const name = document.createElement('h3');
                name.className = 'cart-line-name';
                name.textContent = product.name;
                const unitPrice = document.createElement('p');
                unitPrice.className = 'cart-line-unit';
                unitPrice.textContent = `${formatPrice(product.price)} each`;
                const controls = document.createElement('div');
                controls.className = 'quantity-control';

                const decrement = document.createElement('button');
                decrement.type = 'button';
                decrement.dataset.action = 'decrease';
                decrement.dataset.productId = productId;
                decrement.setAttribute('aria-label', `Decrease ${product.name} quantity`);
                decrement.textContent = '−';
                const count = document.createElement('span');
                count.className = 'quantity-value';
                count.textContent = String(itemQuantity);
                const increment = document.createElement('button');
                increment.type = 'button';
                increment.dataset.action = 'increase';
                increment.dataset.productId = productId;
                increment.setAttribute('aria-label', `Increase ${product.name} quantity`);
                increment.textContent = '+';
                controls.append(decrement, count, increment);
                details.append(name, unitPrice, controls);

                const lineEnd = document.createElement('div');
                lineEnd.className = 'cart-line-end';
                const lineTotal = document.createElement('strong');
                lineTotal.className = 'cart-line-total';
                lineTotal.textContent = formatPrice(product.price * itemQuantity);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove-item';
                remove.dataset.action = 'remove';
                remove.dataset.productId = productId;
                remove.textContent = 'Remove';
                lineEnd.append(lineTotal, remove);

                line.append(image, details, lineEnd);
                cartItemsElement.append(line);
            });
        }

        if (deliverySubmissionSuccessful) {
            try {
                localStorage.removeItem('agrogear-cart');
            } catch (error) {
                showToast('Order was emailed, but this browser could not clear the saved cart.');
            }
        } else {
            loadCart();
        }
        renderCart();
        try {
            if (sessionStorage.getItem(deliveryAccessKey) === 'true') {
                grantDeliveryAccess();
                paymentDeliveryPrompt.hidden = false;
                paymentDetailsReceivedButton.hidden = true;
            }
        } catch (error) {
            showToast('Delivery access could not be remembered for this browser session.');
        }

        cartButton.addEventListener('click', () => cartDialog.showModal());
        document.getElementById('cart-close').addEventListener('click', () => cartDialog.close());
        document.getElementById('delivery-form-link').addEventListener('click', () => cartDialog.close());
        paymentDetailsReceivedButton.addEventListener('click', () => {
            grantDeliveryAccess();
            paymentDeliveryPrompt.hidden = false;
            paymentDetailsReceivedButton.hidden = true;
        });
        deliveryAccessButton.addEventListener('click', () => {
            grantDeliveryAccess();
            deliveryForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        document.getElementById('how-to-pay-link').addEventListener('click', (event) => {
            event.preventDefault();
            setMenuOpen(false);
            paymentInstructions.hidden = false;
            if (!cartDialog.open) cartDialog.showModal();
            paymentInstructions.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        cartDialog.addEventListener('click', (event) => {
            if (event.target === cartDialog) cartDialog.close();
        });

        document.querySelectorAll('.add-button').forEach((button) => {
            button.addEventListener('click', () => {
                const productId = button.dataset.productId;
                if (!productCatalog[productId] || productCatalog[productId].price === null || productCatalog[productId].priceRange) return;
                if ((cart[productId] || 0) >= 50) {
                    showToast('Maximum quantity per item is 50');
                    return;
                }
                cart[productId] = (cart[productId] || 0) + 1;
                saveCart();
                renderCart();
                showToast(`${productCatalog[productId].name} added to cart`);
            });
        });

        cartItemsElement.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            const { action, productId } = button.dataset;
            if (!productCatalog[productId] || productCatalog[productId].price === null || productCatalog[productId].priceRange || !cart[productId]) return;

            if (action === 'increase') {
                if (cart[productId] >= 50) {
                    showToast('Maximum quantity per item is 50');
                    return;
                }
                cart[productId] += 1;
            } else if (action === 'decrease') {
                cart[productId] -= 1;
                if (cart[productId] < 1) delete cart[productId];
            } else if (action === 'remove') {
                delete cart[productId];
            }

            saveCart();
            renderCart();
        });

        checkoutButton.addEventListener('click', () => {
            if (cartQuantity() === 0) return;
            paymentInstructions.hidden = false;
            checkoutButton.textContent = 'Payment contact options shown';
        });

        equipmentToggle.addEventListener('click', () => {
            const isOpen = equipmentToggle.getAttribute('aria-expanded') !== 'true';
            equipmentToggle.setAttribute('aria-expanded', String(isOpen));
            equipmentMenu.classList.toggle('is-open', isOpen);
            categoryMenu.hidden = !isOpen;
        });

        document.querySelectorAll('.category-link').forEach((button) => {
            button.addEventListener('click', () => {
                const categoryId = button.dataset.categoryId;
                const categoryName = categoryNames[categoryId];
                if (!categoryName) return;

                catalogCards.forEach((card) => {
                    card.hidden = card.dataset.categoryId !== categoryId;
                });
                catalogHeading.textContent = categoryName;
                catalogDescription.textContent = `${catalogCards.filter((card) => card.dataset.categoryId === categoryId).length} products`;
                equipmentCatalog.hidden = false;
                equipmentMenu.classList.remove('is-open');
                equipmentToggle.setAttribute('aria-expanded', 'false');
                categoryMenu.hidden = true;
                setMenuOpen(false);
                equipmentCatalog.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        function setMenuOpen(isOpen) {
            mainNav.classList.toggle('is-open', isOpen);
            menuToggle.setAttribute('aria-expanded', String(isOpen));
            menuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
        }

        function grantDeliveryAccess() {
            deliveryAccessGate.hidden = true;
            deliveryForm.hidden = false;
            try {
                sessionStorage.setItem(deliveryAccessKey, 'true');
            } catch (error) {
                showToast('Delivery form is open, but access could not be remembered for this browser session.');
            }
        }

        menuToggle.addEventListener('click', () => {
            setMenuOpen(menuToggle.getAttribute('aria-expanded') !== 'true');
        });

        mainNav.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('a')) {
                setMenuOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setMenuOpen(false);
        });

    </script>
</body>
</html>
