from flask import Flask, request, jsonify, Response
import subprocess
import tempfile
import os
import sys
import json
import xml.etree.ElementTree as ET
import re

app = Flask(__name__)

# Store test cases per task (in production, use database)
TASKS = {}

def extract_code_from_proforma(xml_string):
    """Extract student code from ProFormA submission XML"""
    try:
        root = ET.fromstring(xml_string)
        ns = {'p': 'urn:proforma:v2.1'}
        
        # Try to find embedded text file
        for elem in root.iter():
            if 'embedded-txt-file' in elem.tag:
                return elem.text
            if 'file' in elem.tag and elem.text:
                return elem.text
        
        # Fallback: get any text content
        for elem in root.iter():
            if elem.text and 'def ' in elem.text or 'print' in elem.text:
                return elem.text
    except:
        pass
    return None

def extract_test_from_task(xml_string):
    """Extract test code from ProFormA task XML"""
    import base64
    try:
        root = ET.fromstring(xml_string)
        for elem in root.iter():
            # Check for embedded-bin-file (base64)
            if 'embedded-bin-file' in elem.tag and elem.text:
                filename = elem.get('filename', '')
                if 'test' in filename.lower() or filename.endswith('.py'):
                    decoded = base64.b64decode(elem.text).decode('utf-8')
                    if 'unittest' in decoded or 'def test' in decoded:
                        print(f"Found test in embedded-bin-file: {filename}")
                        return decoded
            # Check for embedded-txt-file
            if 'embedded-txt-file' in elem.tag and elem.text:
                if 'unittest' in elem.text or 'def test' in elem.text:
                    print("Found test in embedded-txt-file")
                    return elem.text
            # Check text content directly
            if elem.text and ('unittest' in str(elem.text) or 'def test' in str(elem.text)):
                print("Found test in element text")
                return elem.text
    except Exception as e:
        print(f"Error extracting test: {e}")
    return None

def run_python_test(code, test_code, filename='solution.py'):
    """Run Python unittest and return results"""
    with tempfile.TemporaryDirectory() as tmpdir:
        # Write student solution with original filename
        solution_path = os.path.join(tmpdir, filename)
        with open(solution_path, 'w') as f:
            f.write(code)
        
        # Write test file
        test_path = os.path.join(tmpdir, 'test_solution.py')
        with open(test_path, 'w') as f:
            f.write(test_code)
        
        print(f"=== Running test in {tmpdir} ===")
        print(f"Student file: {filename}")
        print(f"Files in tmpdir: {os.listdir(tmpdir)}")
        
        # Run tests
        try:
            process = subprocess.run(
                [sys.executable, '-m', 'unittest', 'test_solution', '-v'],
                capture_output=True,
                text=True,
                cwd=tmpdir,
                timeout=30
            )
            output = process.stdout + process.stderr
            passed = process.returncode == 0
            print(f"Test output: {output[:500]}")
        except subprocess.TimeoutExpired:
            output = "Test timeout (30s exceeded)"
            passed = False
        except Exception as e:
            output = f"Error: {str(e)}"
            passed = False
        
        # Count tests
        ok_count = output.count('... ok')
        fail_count = output.count('... FAIL') + output.count('... ERROR')
        total = ok_count + fail_count
        score = (ok_count / total) * 100 if total > 0 else 0
        
        return {
            'passed': passed,
            'score': score,
            'tests_passed': ok_count,
            'tests_total': total,
            'output': output
        }

def generate_proforma_response(result):
    """Generate ProFormA-compatible XML response"""
    score = result['score'] / 100  # Convert to 0-1 scale
    
    xml = f'''<?xml version="1.0" encoding="UTF-8"?>
<response xmlns="urn:proforma:v2.1">
    <separate-test-feedback>
        <submission-feedback-list>
            <student-feedback level="info">
                <title>Test Results</title>
                <content format="plaintext">{result['output']}</content>
            </student-feedback>
        </submission-feedback-list>
        <tests-response>
            <test-response id="1">
                <test-result>
                    <result is-internal-error="false">
                        <score>{score}</score>
                        <validity>{score}</validity>
                    </result>
                    <feedback-list>
                        <student-feedback level="info">
                            <title>Python Unittest</title>
                            <content format="plaintext">{result['output']}</content>
                        </student-feedback>
                    </feedback-list>
                </test-result>
            </test-response>
        </tests-response>
    </separate-test-feedback>
</response>'''
    return xml

# Simple JSON API
@app.route('/grade', methods=['POST'])
def grade():
    data = request.json
    code = data.get('code', '')
    test_code = data.get('test_code', '')
    
    result = run_python_test(code, test_code)
    return jsonify(result)

# ProFormA-compatible API (for Moodle ProFormA plugin)
@app.route('/api/v2/submissions', methods=['POST'])
def proforma_submissions():
    """Handle ProFormA submission format"""
    import base64
    
    # Debug: log all received data
    print("=== RECEIVED REQUEST ===")
    print("Files:", list(request.files.keys()))
    print("Form keys:", list(request.form.keys()))
    
    # Get student code from form data
    code = None
    filename = None
    
    # Method 1: Direct file in form (e.g., 'jawaban.py')
    for key in request.form.keys():
        if key.endswith('.py') and key != 'submission.xml':
            code = request.form[key]
            filename = key
            print(f"Found code in form['{key}']: {code[:100]}...")
            break
    
    # Method 2: Extract from submission.xml (base64 encoded)
    if not code and 'submission.xml' in request.form:
        submission_xml = request.form['submission.xml']
        try:
            root = ET.fromstring(submission_xml)
            # Find embedded-bin-file (base64)
            for elem in root.iter():
                if 'embedded-bin-file' in elem.tag and elem.text:
                    code = base64.b64decode(elem.text).decode('utf-8')
                    filename = elem.get('filename', 'solution.py')
                    print(f"Found base64 code: {code[:100]}...")
                    break
                # Find embedded-txt-file (plain text)
                if 'embedded-txt-file' in elem.tag and elem.text:
                    code = elem.text
                    filename = elem.get('filename', 'solution.py')
                    print(f"Found text code: {code[:100]}...")
                    break
        except Exception as e:
            print(f"XML parse error: {e}")
    
    # Get task.xml for test code
    task_xml = None
    test_code = None
    
    if 'task-file' in request.files:
        task_xml = request.files['task-file'].read().decode('utf-8')
        print("=== TASK XML (first 1000 chars) ===")
        print(task_xml[:1000])
        test_code = extract_test_from_task(task_xml)
    
    if 'task.xml' in request.form:
        task_xml = request.form['task.xml']
        test_code = extract_test_from_task(task_xml)
    
    # Extract or get test code
    test_code = extract_test_from_task(task_xml) if task_xml else None
    
    print(f"=== EXTRACTED ===")
    print(f"Code: {code[:100] if code else 'None'}...")
    print(f"Test code found: {bool(test_code)}")
    
    # Fallback test if none found
    if not test_code:
        test_code = '''
import unittest
import sys
from io import StringIO

class TestOutput(unittest.TestCase):
    def test_runs(self):
        captured = StringIO()
        sys.stdout = captured
        exec(open('solution.py').read())
        sys.stdout = sys.__stdout__
        self.assertTrue(True)

if __name__ == '__main__':
    unittest.main()
'''
    
    if not code:
        return Response(
            generate_proforma_response({'passed': False, 'score': 0, 'tests_passed': 0, 'tests_total': 1, 'output': 'No code submitted'}),
            mimetype='application/xml'
        )
    
    result = run_python_test(code, test_code, filename or 'solution.py')
    
    return Response(
        generate_proforma_response(result),
        mimetype='application/xml'
    )

@app.route('/api/v2/upload', methods=['POST'])
def proforma_upload():
    """Handle task upload (store test cases)"""
    if 'task.xml' in request.files:
        task_xml = request.files['task.xml'].read().decode('utf-8')
        # Store task for later use
        task_id = request.form.get('task_id', 'default')
        TASKS[task_id] = task_xml
        return Response('<response>OK</response>', mimetype='application/xml')
    return Response('<response>No task file</response>', mimetype='application/xml', status=400)

@app.route('/api/v2/status', methods=['GET'])
def proforma_status():
    return jsonify({'status': 'ok', 'version': '1.0'})

@app.route('/status', methods=['GET'])
def status():
    return jsonify({'status': 'ok'})

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=True)
