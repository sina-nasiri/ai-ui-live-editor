# AI UI Live Editor

A powerful Laravel application that allows you to load any website, select HTML sections with your mouse, and use Claude AI to modify them in real-time based on natural language prompts.

## Features

- **Load Any Website**: Enter a URL and load it into the editor
- **Interactive Section Selection**: Hover over sections to highlight them, click to select
- **AI-Powered Editing**: Use natural language to describe changes
- **Real-Time Updates**: See your changes applied instantly
- **Perfect for**:
  - UI/UX designers testing design variations
  - Marketing teams previewing content changes
  - Developers prototyping features
  - Product managers visualizing ideas

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js (optional, for asset compilation)
- Anthropic API Key (Claude AI)

## Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd ai-ui-live-editor
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Add your Claude API key**

   Edit `.env` and add your Anthropic API key:
   ```
   ANTHROPIC_API_KEY=your_api_key_here
   ```

   Get your API key from: https://console.anthropic.com/

5. **Set up database (optional)**

   The app uses SQLite by default. If you want to use another database, update the `.env` file accordingly.

6. **Run migrations (if needed)**
   ```bash
   php artisan migrate
   ```

7. **Start the development server**
   ```bash
   php artisan serve
   ```

8. **Open your browser**

   Navigate to: `http://localhost:8000`

## Usage

1. **Enter a URL**: Type any website URL in the input field and click "Load Website"

2. **Select a Section**:
   - Hover your mouse over different sections of the loaded website
   - Sections will be highlighted with a blue border
   - Click on a section to select it

3. **Describe Your Changes**:
   - A floating textarea will appear at the bottom
   - Type a natural language description of what you want to change
   - Examples:
     - "Make the heading larger and change the color to blue"
     - "Add more padding and make the background gradient"
     - "Change the button text to 'Learn More' and make it rounded"

4. **Apply Changes**:
   - Click "Apply Changes" button
   - Wait for Claude AI to process your request
   - See the changes applied in real-time!

## Example Prompts

- "Make this section have a dark theme with white text"
- "Add a subtle shadow and increase the spacing"
- "Change the font to be more modern and increase the size"
- "Make this button more prominent with a gradient background"
- "Add an animated hover effect to this element"

## How It Works

1. **Proxy System**: The app fetches the target website through a Laravel backend proxy to avoid CORS issues
2. **Section Detection**: JavaScript automatically identifies major HTML sections (headers, sections, articles, etc.)
3. **AI Processing**: Selected HTML and your prompt are sent to Claude AI via the Anthropic API
4. **Real-Time Replacement**: The AI-modified HTML replaces the original section instantly

## Security Notes

- The app uses a proxy to load external websites. This is necessary to avoid CORS restrictions.
- Some websites may not work if they have strict Content Security Policies (CSP)
- Your Claude API key should be kept secure and never committed to version control
- For production use, consider adding rate limiting and authentication

## Troubleshooting

**Website won't load:**
- Some sites prevent embedding via X-Frame-Options headers
- Try a different website or use one you control

**API errors:**
- Verify your ANTHROPIC_API_KEY is correctly set in `.env`
- Check your API key has sufficient credits
- Review logs at `storage/logs/laravel.log`

**Changes not applying:**
- Check browser console for JavaScript errors
- Ensure the selected section is valid HTML
- Try simplifying your prompt

## Tech Stack

- **Backend**: Laravel 12
- **AI**: Claude 3.5 Sonnet (Anthropic API)
- **Frontend**: Vanilla JavaScript, CSS3
- **HTTP Client**: Guzzle (via Laravel HTTP)

## Development

To modify the application:

- **Controller**: `app/Http/Controllers/WebsiteEditorController.php`
- **Routes**: `routes/web.php`
- **Main View**: `resources/views/editor.blade.php`
- **Config**: `.env`

## License

This project is open-sourced software. The Laravel framework is licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Credits

Built with Laravel and powered by Claude AI from Anthropic.
