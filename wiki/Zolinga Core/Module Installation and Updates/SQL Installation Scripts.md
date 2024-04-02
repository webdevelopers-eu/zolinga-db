# SQL Installation Scripts

If you place a `.sql` file in the `{module}/install/install` or `{module}/install/update` directory, it will be executed when the module is installed or updated.

The file may contain multiple SQL statements separated by `;`. The statements will be executed in the order they are defined in the file.

The whole file is executed in a single transaction. If any of the statements fail, the transaction is rolled back and the script is marked as not installed. It will be attempted again on the next installation or update.

# Related

- [Module Installation and Updates](:Zolinga Core:Module Installation and Updates)