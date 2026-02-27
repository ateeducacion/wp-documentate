/^ Summary:/ { found = 1 }

# Print summary lines (only one stat type per line)
found && /^  Classes:/ { print }
found && /^  Methods:/ && !/Lines:/ { print }
found && /^  Lines:/ && !/Methods:/ { print }

# Capture class names (lines starting with letter, not Summary or Code Coverage)
/^[A-Za-z]/ && !/Summary:/ && !/Code Coverage/ { classname = $0 }

# Print class coverage lines with class name prefix
/Methods:.*Lines:/ {
    if (classname != "") {
        printf "%-50s %s\n", classname, $0
        classname = ""
    }
}
