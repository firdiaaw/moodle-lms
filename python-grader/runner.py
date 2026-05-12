import subprocess
import json
import os
import sys

def run_tests():
    # Get the directory where runner.py is located
    base_dir = os.path.dirname(os.path.abspath(__file__))
    tests_dir = os.path.join(base_dir, "tests")
    result_dir = os.path.join(base_dir, "result")
    
    # Ensure result directory exists
    os.makedirs(result_dir, exist_ok=True)
    
    # Check if solution.py exists
    solution_path = os.path.join(base_dir, "solution.py")
    if not os.path.exists(solution_path):
        result = {
            "passed": False,
            "score": 0,
            "output": "Error: solution.py not found"
        }
        with open(os.path.join(result_dir, "result.json"), "w") as f:
            json.dump(result, f, indent=2)
        return
    
    # Run tests from the base directory so imports work
    process = subprocess.run(
        [sys.executable, "-m", "unittest", "discover", "-s", "tests", "-p", "test_*.py", "-v"],
        capture_output=True,
        text=True,
        cwd=base_dir
    )

    passed = process.returncode == 0
    
    # Count tests
    output = process.stdout + process.stderr
    test_count = output.count("... ok") + output.count("... FAIL") + output.count("... ERROR")
    passed_count = output.count("... ok")
    
    # Calculate score
    score = int((passed_count / test_count) * 100) if test_count > 0 else 0

    result = {
        "passed": passed,
        "score": score,
        "tests_run": test_count,
        "tests_passed": passed_count,
        "output": output
    }

    with open(os.path.join(result_dir, "result.json"), "w") as f:
        json.dump(result, f, indent=2)
    
    print(f"Tests: {passed_count}/{test_count} passed")
    print(f"Score: {score}%")

if __name__ == "__main__":
    run_tests()
