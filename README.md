# AutoBloggerImageUploader

A PHP script that automates the following:

1. Fetches two images from Pexels based on a given keyword.  
2. Uploads these images to an Amazon S3 bucket (or another image storage that supports API access).  
3. Injects the uploaded image URLs into specific paragraphs of a provided blog post text.  
4. Publishes the final post to a Blogger.com blog via the Blogger API.

## Features

- **Multiple API Integration**: Uses Pexels API for images, AWS S3 for storage, and Google’s Blogger API for publishing.
- **Configurable**: Set up your own keys, tokens, and project IDs in one place.
- **Paragraph-Specific Image Insertion**: Automatically inserts images after the first and third paragraphs.
- **Composer-Friendly**: Simply install the required libraries via Composer.

## Installation

1. Clone or download this repository.
2. Run the following commands to install required dependencies:
   composer require guzzlehttp/guzzle  
   composer require aws/aws-sdk-php  
   composer require google/apiclient  
3. Update the `define()` constants in `blogger_upload.php` with your keys and configuration:
   - **PEXELS_API_KEY**
   - **AWS_ACCESS_KEY** / **AWS_SECRET_KEY** / **AWS_REGION** / **AWS_BUCKET_NAME**
   - **GOOGLE_CLIENT_SECRET_PATH** / **GOOGLE_TOKEN_PATH**
   - **BLOG_ID** (your Blogger numeric ID)

4. Run `blogger_upload.php` on your local server or hosting environment.  
   - The first time you run it, you’ll be prompted for Google OAuth consent in the browser.  
   - After that, your credentials will be stored in the `GOOGLE_TOKEN_PATH` (usually `token.json`).

## Usage

- Place your blog post title and text in `$blogTitle` and `$blogContent`.  
- Adjust the `$keyword` to define the search term for images.
- Call the script. Once authenticated, it will:  
  1. Retrieve two images from Pexels.  
  2. Upload them to your S3 bucket.  
  3. Insert the images into your text.  
  4. Publish the resulting post on Blogger.

## Contributing

Feel free to open issues or submit pull requests. This script can be extended to support different image services, custom paragraph insertions, or more advanced text parsing.

## License

This project is open-source under the [MIT License](LICENSE).
