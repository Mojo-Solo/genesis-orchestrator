"""
Bridge module to expose mock test framework classes used by Behave steps.
Loads `features/steps/framework_init.py` and re-exports its classes under the
module name `genesis_test_framework` so imports in step files work reliably.
"""

import os
import importlib.util
import sys

_ROOT = os.path.dirname(os.path.abspath(__file__))
_FRAMEWORK_PATH = os.path.join(_ROOT, "features", "steps", "framework_init.py")

spec = importlib.util.spec_from_file_location("_genesis_framework_impl", _FRAMEWORK_PATH)
module = importlib.util.module_from_spec(spec) if spec else None
if spec and spec.loader and module:
    spec.loader.exec_module(module)
else:
    raise ImportError("Unable to load framework_init.py for test framework")

# Re-export expected classes and symbols
for name in getattr(module, "__all__", []):
    globals()[name] = getattr(module, name)

# Ensure this module is discoverable under the expected alias as well
sys.modules.setdefault("genesis_test_framework", sys.modules[__name__])


