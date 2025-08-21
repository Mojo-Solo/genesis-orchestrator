# 🚀 Genesis Frontend Preview Agent

A low-code Claude Code agent integration with Cursor that enables live HTML/JavaScript preview directly inside the IDE. This agent provides real-time visual feedback for frontend development, addressing the community's call for a "dedicated UI mode" with live previews.

## ✨ Features

- **⚡ Instant Reload**: Files auto-refresh via WebSocket injection
- **🤖 AI Integration**: Claude Code can edit and verify UI changes  
- **🎯 In-IDE Preview**: No context switching - everything in Cursor
- **🔧 Low-Code Setup**: Minimal configuration, maximum productivity
- **🌐 Browser Automation**: Optional MCP integration for UI verification
- **📱 Responsive Design**: Mobile-first approach with live preview

## 🏗️ Architecture

The solution comprises three loosely-coupled components:

1. **Claude Code Orchestration (AI Agent)** - Acts as the "brain" with file editing and command execution
2. **Live Preview Server (Local Dev Server)** - Lightweight server with live-reload capability  
3. **Integrated Preview Panel (Cursor UI)** - Browser view embedded inside the IDE

## 🚀 Quick Start

### Prerequisites

- **Cursor IDE** - Download from [cursor.com](https://cursor.com)
- **Node.js** (>= 18) - Required for Claude Code CLI and live-server
- **Claude Code CLI** - Install globally: `npm install -g @anthropic-ai/claude-code`
- **Anthropic API Key** - Set `ANTHROPIC_API_KEY` environment variable

### Installation

1. **Install Dependencies**
   ```bash
   # Install Claude Code CLI
   npm install -g @anthropic-ai/claude-code
   
   # Install live-server for instant reload
   npm install -g live-server
   
   # Verify installations
   claude --version
   live-server --version
   ```

2. **Clone or Setup Project**
   ```bash
   # Use this repository or create your own
   git clone <your-repo>
   cd <project-directory>
   
   # Make preview script executable
   chmod +x preview.sh
   ```

3. **Launch Claude Code**
   ```bash
   # In your project directory
   claude
   ```

### Basic Usage

1. **Start Live Preview**
   ```bash
   ./preview.sh
   # Or specify custom port:
   ./preview.sh 3000
   ```

2. **Open in Cursor**
   - Press `Ctrl+Shift+P` (Command Palette)
   - Type "Simple Browser: Show"
   - Enter: `http://localhost:5500` (or your chosen port)

3. **Start Developing**
   - Edit HTML/CSS/JS files
   - Changes auto-refresh in preview panel
   - Ask Claude to make changes: "Make the header blue"

## 📁 Project Structure

```
genesis-frontend-preview/
├── preview.sh              # Live server launch script
├── .mcp.json              # MCP configuration for browser automation
├── index.html             # Demo HTML file
├── styles.css             # Demo CSS with dark/light themes  
├── script.js              # Interactive JavaScript with Claude integration
└── README.md              # This documentation
```

## 🔧 Configuration

### preview.sh Options

```bash
./preview.sh [PORT] [BROWSER_FLAG]

# Examples:
./preview.sh                    # Default port 5500, no external browser
./preview.sh 3000              # Custom port 3000
./preview.sh 8080 --browser    # Port 8080, open external browser
```

### MCP Configuration (.mcp.json)

The MCP configuration enables browser automation for Claude Code:

```json
{
  "mcpServers": {
    "browser-preview": {
      "command": "browser-mcp",
      "args": [],
      "env": {},
      "description": "Browser automation for UI testing and verification"
    }
  },
  "agentConfig": {
    "name": "Genesis Frontend Preview Agent",
    "defaultPort": 5500,
    "previewUrl": "http://localhost:5500"
  }
}
```

## 🤖 Claude Code Integration

### Available Commands

Ask Claude to perform these actions:

- **"Start the live preview"** - Launches the preview server
- **"Make the header blue"** - Modify CSS styles
- **"Add a navigation bar"** - Create new HTML elements
- **"Center the content"** - Adjust layout and positioning
- **"Make it responsive"** - Add mobile-first responsive design
- **"Test the current page"** - Use browser automation to verify UI

### JavaScript API for Claude

The demo includes `window.genesisAgent` for Claude interaction:

```javascript
// Get current page state
window.genesisAgent.getPageState()

// Apply changes requested by Claude
window.genesisAgent.applyChanges({
  theme: 'light',
  backgroundColor: '#4ecdc4',
  text: 'Hello World!'
})

// Simulate interactions for testing
window.genesisAgent.simulateInteraction('theme-toggle')
```

## 🔬 Advanced Features

### Browser MCP Integration

For enhanced AI capabilities, install browser automation:

```bash
# Install Python MCP server
pip install claude-browser-mcp
playwright install

# Register with Claude Code
claude mcp add browser-preview --scope project browser-mcp
```

This enables Claude to:
- Navigate pages programmatically
- Extract page content and DOM
- Take screenshots for verification
- Click elements and fill forms
- Validate UI changes automatically

### Custom Framework Integration

For React/Vue/Angular projects, modify preview.sh:

```bash
# React/Vite
npm run dev

# Vue CLI  
npm run serve

# Angular
ng serve

# Next.js
npm run dev
```

The agent will detect framework dev servers and use them instead of live-server.

## 🎨 UI/UX Best Practices

### Real-Time Feedback Loop

1. **Edit Code** - Make changes in Cursor editor
2. **Instant Preview** - See changes immediately in preview panel  
3. **AI Verification** - Claude can validate the result
4. **Iterate** - Refine based on visual feedback

### Design Guidelines

- **Mobile-First**: Start with mobile layout, expand to desktop
- **Semantic HTML**: Use proper HTML5 semantic elements
- **Accessible Colors**: Ensure sufficient contrast ratios
- **Performance**: Optimize images and minimize bundle size
- **Progressive Enhancement**: Basic functionality works without JS

## 🛠️ Troubleshooting

### Common Issues

**Live server not starting**
```bash
# Check if port is in use
lsof -i :5500

# Kill process using port
kill -9 $(lsof -t -i:5500)

# Use different port
./preview.sh 3000
```

**Claude Code not responding**
```bash
# Restart Claude Code
claude --reset

# Check API key
echo $ANTHROPIC_API_KEY

# Update to latest version
npm update -g @anthropic-ai/claude-code
```

**Browser automation not working**
```bash
# Reinstall MCP server
pip install --upgrade claude-browser-mcp

# Reinstall browsers
playwright install

# Check MCP configuration
claude mcp list
```

### Performance Tips

- **Use .gitignore**: Exclude `node_modules`, `.DS_Store`, build files
- **Optimize Images**: Compress images, use modern formats (WebP, AVIF)
- **Minimize Dependencies**: Keep package.json lean
- **Enable Gzip**: Configure server compression
- **Cache Assets**: Use proper cache headers

## 🔄 Workflow Examples

### Creating a Landing Page

1. **Start with Claude**: "Create a modern landing page with hero section"
2. **Preview Live**: See the initial layout in preview panel
3. **Iterate Design**: "Make the hero background gradient and add animations"
4. **Test Responsive**: "Ensure it looks good on mobile"
5. **Verify Accessibility**: "Check color contrast and add alt tags"

### Building Components

1. **Component Request**: "Create a card component with image, title, and description"
2. **Style Refinement**: "Add hover effects and rounded corners"  
3. **Interactive Elements**: "Add click handlers and state management"
4. **Integration**: "Use this card in a grid layout"

### Debugging UI Issues

1. **Issue Description**: "The sidebar is not visible on mobile"
2. **AI Analysis**: Claude inspects the CSS and identifies the problem
3. **Quick Fix**: "Adjust the media query breakpoint"
4. **Verification**: Claude uses browser automation to confirm the fix

## 📚 Resources

- [Claude Code Documentation](https://docs.anthropic.com/en/docs/claude-code)
- [Cursor IDE Features](https://cursor.com/features)
- [live-server NPM Package](https://www.npmjs.com/package/live-server)
- [MCP Protocol Specification](https://github.com/anthropic/model-context-protocol)
- [Browser MCP Server](https://glama.ai/mcp/servers/@sac916/claude-browser-mcp)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature-name`
3. Make your changes and test thoroughly
4. Submit a pull request with detailed description

## 📄 License

MIT License - feel free to use this in your own projects!

## 🙏 Acknowledgments

- **Anthropic** for Claude Code and MCP protocol
- **Cursor Team** for the excellent AI-enhanced IDE
- **Community** for feedback and feature requests
- **Open Source** contributors for tools like live-server and Playwright

---

**🚀 Ready to revolutionize your frontend development workflow?**

Start with `./preview.sh` and experience the future of AI-assisted web development!