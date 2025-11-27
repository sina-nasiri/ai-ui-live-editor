# AI UI Live Editor

A powerful Laravel-based web application that allows you to visually edit any website using AI. Load any website, select elements with your mouse, describe changes in natural language, and watch Claude AI transform your design in real-time.

![AI UI Live Editor](https://img.shields.io/badge/Laravel-10.x-red) ![Claude AI](https://img.shields.io/badge/Claude-Sonnet%204.5-purple) ![License](https://img.shields.io/badge/License-MIT-green)

## Features

- **Load Any Website** - Enter any URL and load it through a secure proxy
- **Visual Element Selection** - Click on any element to select it with visual highlighting
- **AI-Powered Editing** - Describe changes in natural language and let Claude AI modify the HTML
- **Copy HTML** - Right-click any element to copy its formatted HTML code
- **Screenshot Elements** - Capture screenshots of individual elements as PNG
- **Modern UI** - Clean, responsive interface with modals, toast notifications
- **API Key Management** - Set your Anthropic API key directly in the UI (stored locally in browser)
- **Real-Time Preview** - See changes applied instantly without page refresh

**Perfect for:**
- UI/UX designers testing design variations
- Marketing teams previewing content changes
- Developers prototyping features quickly
- Product managers visualizing ideas
- Learning HTML/CSS through AI assistance

## Screenshots

### Main Interface
- Clean navbar with URL input and settings
- Full-width website preview
- Status bar showing selected element path

### Element Selection
- Blue highlight on hover
- Green highlight on selection
- Right-click toolbar for copy/screenshot

### Edit Modal
- HTML preview of selected element
- Natural language prompt input
- Quick suggestion chips

## Requirements

- PHP 8.1 or higher
- Composer
- Anthropic API Key ([Get one here](https://console.anthropic.com/settings/keys))

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/sina-nasiri/ai-ui-live-editor.git
cd ai-ui-live-editor
```

### 2. Install dependencies

```bash
composer install
```

### 3. Environment setup

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configure environment

Edit `.env` file:

```env
APP_URL=http://localhost:8000
SESSION_DRIVER=file
CACHE_STORE=file
```

### 5. Start the development server

```bash
php artisan serve
```

Visit `http://localhost:8000` in your browser.

## Usage

### Getting Started

1. **Set API Key** - Click the Settings icon (⚙️) in the top navigation and enter your Anthropic API key
2. **Load a Website** - Enter any URL in the input field and click "Load"
3. **Select an Element** - Click on any element in the preview (highlighted in green when selected)
4. **Describe Changes** - In the modal that appears, describe what changes you want in natural language
5. **Apply Changes** - Click "Apply Changes" and watch the AI transform your selection

### Copy HTML & Screenshot

**Right-click** on any element to open the toolbar:
- **Copy HTML** - Copies the element's formatted HTML to your clipboard
- **Screenshot** - Downloads the element as a PNG image

### Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Esc` | Close modal |
| `Ctrl + Enter` | Apply changes (when modal is open) |

### Example Prompts

```
"Make this larger and add a blue gradient background"
"Change the font to be more modern and increase padding"
"Add rounded corners and a subtle shadow"
"Make this section responsive with flexbox"
"Change the color scheme to dark mode"
"Add a hover effect with smooth transition"
"Make the text bold and center it"
"Add a border and change background to light gray"
```

### Quick Suggestion Chips

Click on any suggestion chip for common modifications:
- Make larger
- Blue background
- Round corners
- Modernize
- Add shadow
- Center content

## Deployment

### cPanel / Shared Hosting

1. Upload all files to your hosting directory
2. Point your domain to the `/public` folder
3. Update `.env` with your production URL:

```env
APP_URL=https://yourdomain.com/ai-ui-live-editor/public
```

4. Ensure proper permissions:

```bash
chmod -R 755 storage bootstrap/cache
```

### Apache Configuration

Ensure `mod_rewrite` is enabled. The `.htaccess` file in `/public` handles URL rewriting.

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/ai-ui-live-editor/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Project Structure

```
ai-ui-live-editor/
├── app/
│   └── Http/
│       └── Controllers/
│           └── WebsiteEditorController.php  # Main controller (proxy & AI)
├── resources/
│   └── views/
│       └── editor.blade.php                 # Main frontend view
├── routes/
│   └── web.php                              # Route definitions
├── public/
│   └── index.php                            # Application entry point
├── storage/
│   └── logs/                                # Application logs
└── .env                                     # Environment configuration
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/` | Main editor interface |
| `POST` | `/proxy` | Fetch and proxy external websites |
| `POST` | `/edit-section` | Process AI edit requests |

## How It Works

### 1. Proxy Loading
When you enter a URL, the app fetches the website content server-side, converts relative URLs to absolute, and injects custom CSS/JS for the editor functionality.

### 2. Element Selection
Custom JavaScript makes all visible elements selectable with:
- Blue border on hover
- Green border on selection
- Right-click context menu for tools

### 3. AI Processing
Your prompt and the selected HTML are sent to Claude AI (Sonnet 4.5), which returns modified HTML with inline styles for immediate compatibility.

### 4. Live Update
The modified HTML replaces the original element in the preview, giving you instant visual feedback without page refresh.

## Configuration

### AI Model

The default model is `claude-sonnet-4-5-20250929`. To change it, edit `WebsiteEditorController.php`:

```php
'model' => 'claude-sonnet-4-5-20250929',
```

### AI System Prompt

The AI prompt can be customized in `WebsiteEditorController.php` in the `editSection` method:

```php
'content' => "You are a UI/UX expert. Modify the HTML below based on the user's request.

RULES:
1. Use INLINE STYLES (style=\"...\") for all CSS - NO <style> tags
2. Keep existing class names, add inline styles to override
3. Return ONLY raw HTML - NO markdown, NO code blocks, NO explanation
4. Preserve original structure and attributes

HTML:
{$html}

Request: {$prompt}

Output the modified HTML only:"
```

## Troubleshooting

### "Failed to load website"
- Check if the target website allows proxying
- Some websites block server requests
- Try a different website to test
- Check `storage/logs/laravel.log` for details

### Elements not selectable
- Wait for the page to fully load (3-5 seconds)
- Some dynamic content takes time to become selectable
- The editor re-initializes multiple times to catch late-loading content
- Try scrolling to make elements visible

### API Key errors
- Ensure your key starts with `sk-ant-`
- Verify key is valid at [console.anthropic.com](https://console.anthropic.com)
- Check you have available API credits
- Key is stored in browser localStorage

### Styles not applying correctly
- The AI uses inline styles which override most CSS
- Some websites have aggressive `!important` declarations
- Try being more specific in your prompt
- Check browser console for JavaScript errors

### Copy/Screenshot not working
- Right-click on the element to show the toolbar
- Ensure you're clicking inside the preview iframe
- Check browser permissions for clipboard access

## Security Notes

- **API Keys**: Stored in browser localStorage, only sent to Anthropic API
- **Proxy**: Only fetches content, doesn't execute target website server code
- **CSRF**: Protection enabled on all POST endpoints
- **No Storage**: Website content is not stored on server

## Tech Stack

- **Backend**: Laravel 10.x (PHP 8.1+)
- **AI**: Claude Sonnet 4.5 (Anthropic API)
- **Frontend**: Vanilla JavaScript, CSS3
- **HTTP Client**: Guzzle (via Laravel HTTP facade)

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Roadmap

- [ ] Export modified HTML/CSS
- [ ] Save/load editing sessions
- [ ] Multiple element selection
- [ ] Undo/redo functionality
- [ ] Template library
- [ ] Collaborative editing

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).

## Acknowledgments

- [Laravel](https://laravel.com) - The PHP framework for web artisans
- [Anthropic Claude](https://anthropic.com) - AI that powers the intelligent editing
- [Inter Font](https://rsms.me/inter/) - Beautiful UI typography

---

**Made with ❤️ and AI**

Built by [Sina Nasiri](https://github.com/sina-nasiri)
