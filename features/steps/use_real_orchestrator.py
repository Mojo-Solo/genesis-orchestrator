#!/usr/bin/env python3
"""
Switch BDD tests to use real orchestrator instead of mocks.

Usage:
    python use_real_orchestrator.py --enable    # Use real orchestrator
    python use_real_orchestrator.py --disable   # Use mock orchestrator
    python use_real_orchestrator.py --status    # Check current mode
"""

import sys
import shutil
from pathlib import Path


def enable_real_orchestrator():
    """Enable real orchestrator for BDD tests."""
    steps_dir = Path(__file__).parent
    
    # Backup original mock
    mock_file = steps_dir / "framework_init.py"
    backup_file = steps_dir / "framework_init.mock.bak"
    real_file = steps_dir / "framework_real.py"
    
    if mock_file.exists() and not backup_file.exists():
        shutil.copy2(mock_file, backup_file)
        print(f"✓ Backed up mock to {backup_file}")
    
    # Copy real implementation over
    if real_file.exists():
        shutil.copy2(real_file, mock_file)
        print(f"✓ Enabled real orchestrator implementation")
        print(f"  Tests will now use actual backend API at http://localhost:8000")
        return True
    else:
        print(f"✗ Real implementation not found at {real_file}")
        return False


def disable_real_orchestrator():
    """Disable real orchestrator and restore mocks."""
    steps_dir = Path(__file__).parent
    
    mock_file = steps_dir / "framework_init.py"
    backup_file = steps_dir / "framework_init.mock.bak"
    
    if backup_file.exists():
        shutil.copy2(backup_file, mock_file)
        print(f"✓ Restored mock implementation")
        print(f"  Tests will now use mock orchestrator")
        return True
    else:
        print(f"✗ Mock backup not found at {backup_file}")
        print(f"  Run with --enable first to create backup")
        return False


def check_status():
    """Check whether real or mock orchestrator is enabled."""
    steps_dir = Path(__file__).parent
    mock_file = steps_dir / "framework_init.py"
    
    if not mock_file.exists():
        print("✗ framework_init.py not found")
        return
    
    with open(mock_file, 'r') as f:
        content = f.read()
    
    if 'BACKEND_API_URL' in content and 'UnifiedMCPOrchestrator' in content:
        print("✓ Real orchestrator is ENABLED")
        print("  Backend API: http://localhost:8000/api/v1")
        print("  Health API: http://localhost:8000/health")
    elif 'Mock' in content:
        print("✓ Mock orchestrator is ENABLED")
        print("  Tests use in-memory mock implementations")
    else:
        print("? Unknown orchestrator configuration")


def main():
    """Main entry point."""
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    
    command = sys.argv[1]
    
    if command == '--enable':
        success = enable_real_orchestrator()
        sys.exit(0 if success else 1)
    elif command == '--disable':
        success = disable_real_orchestrator()
        sys.exit(0 if success else 1)
    elif command == '--status':
        check_status()
        sys.exit(0)
    else:
        print(__doc__)
        sys.exit(1)


if __name__ == '__main__':
    main()