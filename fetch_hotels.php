<?php

//Configuration information section

$rapidApiKey  = "36b2f13aeemsh5381098411c5e19p12dd42jsnbb0bff1d4e7b";
$rapidApiHost = "booking-com.p.rapidapi.com";

$checkinDate  = "2026-01-31";
$checkoutDate = "2026-02-01";
$childrenNumber = 1;
$adultsNumber = 2;
$roomNumber = 1;

$apiEndpoint = "https://booking-com.p.rapidapi.com/v1/hotels/search-by-coordinates";

$headers = [
    "X-RapidAPI-Key: {$rapidApiKey}",
    "X-RapidAPI-Host: {$rapidApiHost}",
];

$queryParams = [
    "page_number"           => 0,
    "locale"                => "en-gb",
    "children_number"       => $childrenNumber,
    "checkout_date"         => $checkoutDate,
    "checkin_date"          => $checkinDate,
    "adults_number"         => $adultsNumber,
    "units"                 => "metric",
    "latitude"              => 25.276987,
    "room_number"           => $roomNumber,
    "order_by"              => "popularity",
    "include_adjacency"     => "true",
    "longitude"             => 55.296249,
    "categories_filter_ids" => "class::2,class::4,free_cancellation::1",
    "children_ages"         => "5",
    "filter_by_currency"    => "AED",
];

$finalUrl = $apiEndpoint . "?" . http_build_query($queryParams);

//Initiating cURL request

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL            => $finalUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => $headers,
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);

curl_close($curl);

//Error handling for response

if ($httpCode !== 200 || $response === false) {
    http_response_code(500);

    echo json_encode([
        "apiError"  => true,
        "httpCode"  => $httpCode,
        "curlError" => $curlError,
        "raw"       => $response,
    ], JSON_PRETTY_PRINT);

    exit;
}

$data = json_decode($response, true);

// Validate API structure
if (!isset($data["result"]) || !is_array($data["result"])) {
    echo json_encode([
        "error" => "Unexpected API response.",
        "raw"   => $data,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Safely fetches nested array values without triggering notices.

function getNestedValue(array $source, array $keys, mixed $default = null): mixed
{
    $value = $source;

    foreach ($keys as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }

        $value = $value[$key];
    }

    return $value;
}

//Building hotel data as per required structure

$hotelData = [];

foreach ($data["result"] as $hotel) {
    // echo '<pre>';
    // print_r($hotel);
    // echo '</pre>';
    // exit;
    // Extract actual price using best available source
    $price = getNestedValue($hotel, ["price_breakdown", "gross_price"])
        ?? getNestedValue($hotel, ["composite_price_breakdown", "gross_amount", "value"])
        ?? ($hotel["min_total_price"] ?? null)
        ?? 0;

    $pricePerNight = getNestedValue($hotel, ["composite_price_breakdown", "gross_amount_per_night", "value"])
        ?? $price;

    $currency = getNestedValue($hotel, ["price_breakdown", "currency"])
        ?? getNestedValue($hotel, ["composite_price_breakdown", "gross_amount", "currency"])
        ?? ($hotel["currency_code"] ?? null)
        ?? ($hotel["currencycode"] ?? null)
        ?? "USD";

    // Extract service fee
    $serviceFee = 0.0;
    $items = getNestedValue($hotel, ["composite_price_breakdown", "items"], []);
    if (is_array($items)) {
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item["name"])) {
                continue;
            }

            if (strcasecmp($item["name"], "Service charge") === 0) {
                $feeValue = getNestedValue($item, ["item_amount", "value"]);

                if ($feeValue !== null) {
                    $serviceFee = round(floatval($feeValue), 2);
                }

                break;
            }
        }
    }

    // Extract main image
    $img = $hotel["max_photo_url"] ?? "";

    // Required structure (snake_case) EXACTLY per your specification
    $hotelData[] = (object) [
        "hotel_id"               => $hotel["hotel_id"] ?? "",
        "img"                    => $img,
        "name"                   => $hotel["hotel_name"] ?? "",
        "location"               => ($hotel["city"] ?? "") . ' ' . ($hotel["country"] ?? ""),
        "address"                => $hotel["address"] ?? "",
        "stars"                  => $hotel["class"] ?? 0,
        "rating"                 => $hotel["review_score"] ?? 0,
        "latitude"               => isset($hotel["latitude"]) ? (float)$hotel["latitude"] : 0,
        "longitude"              => isset($hotel["longitude"]) ? (float)$hotel["longitude"] : 0,

        // required price format
        "actual_price"           => round(floatval($price), 2),
        "actual_price_per_night" => round(floatval($pricePerNight), 2),
        "markup_price"           => round(floatval($price * 1.15), 2),
        "markup_price_per_night" => round(floatval($pricePerNight * 1.15), 2),

        "currency"               => $currency,
        "booking_currency"       => $currency,
        "service_fee"            => $serviceFee,
        "supplier_name"          => "hotels",
        "supplier_id"            => "1",
        "redirect"               => "",
        "booking_data"           => [],
        "color"                  => "#FF9900",
    ];
}

//Output JSON response

header("Content-Type: application/json");
echo json_encode($hotelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
