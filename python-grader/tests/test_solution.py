import unittest
from solution import add

class TestAdd(unittest.TestCase):

    def test_basic(self):
        self.assertEqual(add(2, 3), 5)

    def test_negative(self):
        self.assertEqual(add(-1, -1), -2)

if __name__ == "__main__":
    unittest.main()
