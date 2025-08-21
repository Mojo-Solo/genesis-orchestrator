// Genesis Frontend Preview Agent - Interactive JavaScript
// Demonstrates live preview capabilities with real-time updates

// Theme Management
let currentTheme = 'dark';

function changeTheme() {
    const body = document.body;
    const btn = event.target;
    
    if (currentTheme === 'dark') {
        body.classList.add('light-theme');
        btn.textContent = '🌙 Dark Theme';
        currentTheme = 'light';
        showNotification('Switched to Light Theme! 🌞');
    } else {
        body.classList.remove('light-theme');
        btn.textContent = '🎨 Light Theme';
        currentTheme = 'dark';
        showNotification('Switched to Dark Theme! 🌙');
    }
    
    // Demonstrate Claude Code's ability to detect theme changes
    console.log(`Theme changed to: ${currentTheme}`);
}

// Dynamic Element Addition
let elementCounter = 1;

function addElement() {
    const container = document.querySelector('.features .feature-grid');
    const newCard = document.createElement('div');
    newCard.className = 'feature-card';
    newCard.style.animation = 'fadeInUp 0.5s ease';
    
    newCard.innerHTML = `
        <div class="feature-icon">✨</div>
        <h4>Dynamic Element ${elementCounter}</h4>
        <p>Added via live JavaScript interaction</p>
        <button class="btn btn-secondary" onclick="removeElement(this)" style="margin-top: 1rem; font-size: 0.8rem;">
            🗑️ Remove
        </button>
    `;
    
    container.appendChild(newCard);
    elementCounter++;
    
    showNotification(`Added Dynamic Element ${elementCounter - 1}! ✨`);
    
    // Scroll to the new element
    newCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function removeElement(btn) {
    const card = btn.closest('.feature-card');
    card.style.animation = 'fadeOutDown 0.3s ease';
    
    setTimeout(() => {
        card.remove();
        showNotification('Element removed! 🗑️');
    }, 300);
}

// Demo Section Interactivity
function updateBackground(color) {
    const output = document.getElementById('demo-output');
    output.style.background = color;
    
    // Update text color based on brightness
    const brightness = getBrightness(color);
    output.style.color = brightness > 128 ? '#000000' : '#ffffff';
    
    showNotification(`Background updated to ${color}! 🎨`);
}

function updateDemoText(text) {
    const output = document.getElementById('demo-output');
    output.textContent = text || 'Hello Genesis!';
    
    showNotification(`Text updated! ✏️`);
}

// Utility Functions
function getBrightness(hexColor) {
    const rgb = hexToRgb(hexColor);
    return (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
}

function hexToRgb(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? {
        r: parseInt(result[1], 16),
        g: parseInt(result[2], 16),
        b: parseInt(result[3], 16)
    } : null;
}

// Notification System
function showNotification(message) {
    // Remove existing notifications
    const existing = document.querySelector('.notification');
    if (existing) existing.remove();
    
    const notification = document.createElement('div');
    notification.className = 'notification';
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        z-index: 1000;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        max-width: 300px;
        word-wrap: break-word;
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Live Preview Status Monitor
function updateLiveStatus() {
    const statusElement = document.getElementById('status');
    const dot = statusElement.querySelector('.status-dot');
    
    // Simulate checking live-server connection
    fetch(window.location.href)
        .then(() => {
            dot.style.background = '#06d6a0'; // Green - Active
            statusElement.innerHTML = '<span class="status-dot"></span>Live Preview Active';
        })
        .catch(() => {
            dot.style.background = '#ef4444'; // Red - Inactive
            statusElement.innerHTML = '<span class="status-dot"></span>Live Preview Disconnected';
        });
}

// Smooth Scrolling for Navigation
document.addEventListener('DOMContentLoaded', function() {
    // Add smooth scrolling to navigation links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Initialize live status check
    updateLiveStatus();
    setInterval(updateLiveStatus, 10000); // Check every 10 seconds
    
    // Add CSS animations if not already defined
    if (!document.querySelector('#dynamic-animations')) {
        const style = document.createElement('style');
        style.id = 'dynamic-animations';
        style.textContent = `
            @keyframes fadeInUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            @keyframes fadeOutDown {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(20px); }
            }
            
            @keyframes slideInRight {
                from { opacity: 0; transform: translateX(100%); }
                to { opacity: 1; transform: translateX(0); }
            }
            
            @keyframes slideOutRight {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(100%); }
            }
        `;
        document.head.appendChild(style);
    }
    
    // Show welcome message
    setTimeout(() => {
        showNotification('Genesis Preview Agent loaded! 🚀');
    }, 1000);
});

// Claude Code Integration Helpers
// These functions can be called by Claude Code via browser automation

window.genesisAgent = {
    // Get current page state for Claude to analyze
    getPageState: function() {
        return {
            theme: currentTheme,
            elementCount: document.querySelectorAll('.feature-card').length,
            url: window.location.href,
            title: document.title,
            timestamp: new Date().toISOString()
        };
    },
    
    // Apply changes requested by Claude
    applyChanges: function(changes) {
        if (changes.theme && changes.theme !== currentTheme) {
            changeTheme();
        }
        
        if (changes.addElements) {
            for (let i = 0; i < changes.addElements; i++) {
                addElement();
            }
        }
        
        if (changes.backgroundColor) {
            updateBackground(changes.backgroundColor);
        }
        
        if (changes.text) {
            updateDemoText(changes.text);
        }
        
        return this.getPageState();
    },
    
    // Simulate user interactions for testing
    simulateInteraction: function(type) {
        switch(type) {
            case 'theme-toggle':
                changeTheme();
                break;
            case 'add-element':
                addElement();
                break;
            case 'random-color':
                const colors = ['#ff6b6b', '#4ecdc4', '#45b7d1', '#96ceb4', '#feca57'];
                updateBackground(colors[Math.floor(Math.random() * colors.length)]);
                break;
            default:
                showNotification('Unknown interaction type');
        }
    }
};

// Expose for Claude Code debugging
console.log('Genesis Frontend Preview Agent loaded!');
console.log('Available methods:', Object.keys(window.genesisAgent));