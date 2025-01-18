<?php
/**
 * To install required packages, run these commands in your terminal:
 * 
 * composer require guzzlehttp/guzzle
 * composer require aws/aws-sdk-php
 * composer require google/apiclient
 */

session_start(); // For OAuth session handling

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client as HttpClient;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Google_Client;
use Google_Service_Blogger;
use Google_Service_Blogger_Post;

/* ------------------------------------------------------
   A) SETTINGS
------------------------------------------------------ */

/**
 * Pexels API key for fetching images
 */
define('PEXELS_API_KEY', 'YOUR_PEXELS_API_KEY');

/**
 * AWS S3 credentials
 */
define('AWS_ACCESS_KEY', 'YOUR_AWS_ACCESS_KEY');
define('AWS_SECRET_KEY', 'YOUR_AWS_SECRET_KEY');
define('AWS_REGION', 'eu-central-1'); // Change to your region (e.g., us-east-1)
define('AWS_BUCKET_NAME', 'your-s3-bucket-name');

/**
 * Blogger API credentials:
 * The JSON file you downloaded from Google Cloud Console
 */
define('GOOGLE_CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');

/**
 * The file that will store your token after Google OAuth
 */
define('GOOGLE_TOKEN_PATH', __DIR__ . '/token.json');

/**
 * The numeric ID of your Blogger blog (e.g. 1234567890123456789)
 */
define('BLOG_ID', '1234567890123456789');

/**
 * Example blog post title and content (in Turkish here, but can be any language).
 * Make sure the content is at least 500 words if needed.
 */
$blogTitle = "Television Hakkında Her Şey";
$blogContent = <<<EOT
Televizyon (television), icadıyla birlikte kitle iletişim araçları arasında en önemli konuma yerleşen bir aygıttır.

Bu cihazların kökeni 19. yüzyıla kadar uzanmaktadır. İlk zamanlarda ...

(Paragraflarınız burada devam eder, en az 500 kelime olduğunu varsayıyoruz.)

Daha fazla bilgi için...
EOT;

/**
 * The keyword for searching images on Pexels (or any other stock service)
 */
$keyword = "television";

/* ------------------------------------------------------
   B) FETCH 2 IMAGE LINKS FROM PEXELS API
------------------------------------------------------ */
function getPexelsImages($keyword, $count = 2)
{
    // Pexels API documentation: https://www.pexels.com/api/
    $client = new HttpClient([
        'base_uri' => 'https://api.pexels.com/v1/',
        'headers' => [
            'Authorization' => PEXELS_API_KEY
        ]
    ]);

    $response = $client->get('search', [
        'query' => [
            'query' => $keyword,
            'per_page' => $count
        ]
    ]);

    $data = json_decode($response->getBody()->getContents(), true);
    $imageUrls = [];

    // Extract URLs from the returned JSON structure
    if (!empty($data['photos'])) {
        foreach ($data['photos'] as $photo) {
            // We can choose 'large', 'original', or other sizes provided by Pexels
            $imageUrls[] = $photo['src']['large'];
        }
    }
    return $imageUrls;
}

/* ------------------------------------------------------
   C) UPLOAD THE IMAGES TO S3 AND RETURN THE URL
------------------------------------------------------ */
function uploadImageToS3($imageUrl)
{
    // Download the image temporarily into memory
    $imageData = file_get_contents($imageUrl);
    if (!$imageData) {
        throw new Exception("Failed to download image: " . $imageUrl);
    }

    // Attempt to detect the file extension
    $extension = pathinfo($imageUrl, PATHINFO_EXTENSION);
    if (empty($extension)) {
        $extension = 'jpg'; // default to jpg if unknown
    }

    // Create a unique file name in S3
    $fileName = 'images/' . uniqid('img_') . '.' . $extension;

    // Initialize the AWS S3 client
    $s3 = new S3Client([
        'version'     => 'latest',
        'region'      => AWS_REGION,
        'credentials' => [
            'key'    => AWS_ACCESS_KEY,
            'secret' => AWS_SECRET_KEY,
        ]
    ]);

    try {
        $result = $s3->putObject([
            'Bucket'      => AWS_BUCKET_NAME,
            'Key'         => $fileName,
            'Body'        => $imageData,
            'ACL'         => 'public-read', // Make it publicly accessible
            'ContentType' => 'image/jpeg'
        ]);

        // Return the public URL to the uploaded object
        return $result['ObjectURL'];

    } catch (AwsException $e) {
        throw new Exception("Error uploading to S3: " . $e->getMessage());
    }
}

/* ------------------------------------------------------
   D) SET UP BLOGGER API CONNECTION
------------------------------------------------------ */
function getBloggerService()
{
    $client = new Google_Client();
    $client->setScopes(['https://www.googleapis.com/auth/blogger']);
    $client->setAuthConfig(GOOGLE_CLIENT_SECRET_PATH);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // If we already have a token saved, load it
    if (file_exists(GOOGLE_TOKEN_PATH)) {
        $accessToken = json_decode(file_get_contents(GOOGLE_TOKEN_PATH), true);
        $client->setAccessToken($accessToken);
    }

    // If the token is expired, refresh or get a new one
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // If we don't have a refresh token, initiate the OAuth flow
            if (!isset($_GET['code'])) {
                $authUrl = $client->createAuthUrl();
                echo "Please open the following URL in your browser to authorize:\n";
                echo "<a href='$authUrl' target='_blank'>$authUrl</a>";
                exit;
            } else {
                $client->authenticate($_GET['code']);
            }
        }
        file_put_contents(GOOGLE_TOKEN_PATH, json_encode($client->getAccessToken()));
    }

    return new Google_Service_Blogger($client);
}

/* ------------------------------------------------------
   E) INSERT IMAGES INTO SPECIFIC PARAGRAPHS
------------------------------------------------------ */
function insertImagesIntoParagraphs($content, $imageUrl1, $imageUrl2)
{
    // Split text by double newlines to identify paragraphs
    $paragraphs = preg_split("/(\r?\n){2,}|\n{2,}/", $content);
    $finalHtml = "";

    foreach ($paragraphs as $index => $para) {
        $para = trim($para);
        if (empty($para)) {
            continue;
        }
        // Wrap each paragraph in <p> tags
        $finalHtml .= "<p>" . nl2br($para) . "</p>\n";

        // Insert the first image after the first paragraph
        if ($index == 0 && $imageUrl1) {
            $finalHtml .= '<img src="' . $imageUrl1 . '" alt="Image1" />' . "\n";
        }
        // Insert the second image after the third paragraph
        if ($index == 2 && $imageUrl2) {
            $finalHtml .= '<img src="' . $imageUrl2 . '" alt="Image2" />' . "\n";
        }
    }
    return $finalHtml;
}

/* ------------------------------------------------------
   F) PUBLISH TO BLOGGER
------------------------------------------------------ */
function postToBlogger($title, $htmlContent)
{
    // Get an authenticated Blogger service instance
    $service = getBloggerService();
    $post = new Google_Service_Blogger_Post();
    $post->setTitle($title);
    $post->setContent($htmlContent);

    // Publish the post immediately (isDraft=false)
    $result = $service->posts->insert(BLOG_ID, $post, ['isDraft' => false]);

    echo "A new post has been published on Blogger.\n";
    echo "Post ID: " . $result->id . "\n";
    echo "URL: " . $result->url . "\n";
}

/* ------------------------------------------------------
   G) BRING IT ALL TOGETHER
------------------------------------------------------ */

// 1) Fetch two image URLs related to the chosen keyword
$imageUrls = getPexelsImages($keyword, 2);
if (count($imageUrls) < 2) {
    die("Not enough images found or a Pexels API issue occurred.\n");
}

// 2) Upload these images to S3 and retrieve their public URLs
$s3Url1 = uploadImageToS3($imageUrls[0]);
$s3Url2 = uploadImageToS3($imageUrls[1]);

// 3) Insert the uploaded image URLs into the blog content
$finalHtmlContent = insertImagesIntoParagraphs($blogContent, $s3Url1, $s3Url2);

// 4) Publish to Blogger
postToBlogger($blogTitle, $finalHtmlContent);
echo "Process completed successfully!\n";
