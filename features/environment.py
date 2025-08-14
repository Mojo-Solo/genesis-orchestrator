"""
Behave environment for GENESIS tests.
Ensures the mock test framework module alias is registered before step modules load.
"""

# Importing this module sets sys.modules['genesis_test_framework']
from steps import framework_init  # noqa: F401

def before_all(context):
    # Nothing else needed; alias is established by import above
    pass


