<?php
require_once 'config.php';

try {
    // Get database connection
    $conn = getDBConnection();
    
    // Test if connection works
    $stmt = $conn->query("SELECT 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "<h2>Database Connection Test</h2>";
        echo "<p style='color: green;'>✅ Connection successful!</p>";
        
        // Test a simple query with parameters
        echo "<h3>Testing PDO Parameter Binding</h3>";
        $testValue = 'test';
        $stmt = $conn->prepare("SELECT ? AS test_value");
        $stmt->execute([$testValue]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['test_value'] === 'test') {
            echo "<p style='color: green;'>✅ Parameter binding works correctly</p>";
        } else {
            echo "<p style='color: red;'>❌ Parameter binding failed</p>";
        }
        
        // Display connection info
        echo "<h3>Connection Information</h3>";
        echo "<ul>";
        echo "<li>PDO Driver: " . $conn->getAttribute(PDO::ATTR_DRIVER_NAME) . "</li>";
        echo "<li>Server Version: " . $conn->getAttribute(PDO::ATTR_SERVER_VERSION) . "</li>";
        echo "<li>Client Version: " . $conn->getAttribute(PDO::ATTR_CLIENT_VERSION) . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>❌ Connection test failed</p>";
    }
} catch (PDOException $e) {
    echo "<h2>Database Connection Error</h2>";
    echo "<p style='color: red;'>❌ " . $e->getMessage() . "</p>";
}
?>
<p><a href="index.php">Return to homepage</a></p>