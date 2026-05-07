<?php
require_once LIB_CORE . 'Mongo.php';


class xenDataTables extends Mongo {
    
    // Constructor for DataTables class
    public function __construct( $workspace_hash, $component_name = 'datatable' ) {

        // Call the parent constructor to set up the Mongo connection
        parent::__construct();

        $this->workspace_hash = $workspace_hash;
        $this->component_name = $component_name;

    }

    public function getDataTablesList(){

    }

    public function getDataTable( $tableHash ) {

        //  Get MetaData First For The Columns
        $metaDataCollection = $this->getDataTableMetaDataCollection();

        $metaData = $metaDataCollection->findOne( ['tableId' => $tableHash ] );

        if( $metaData == null ) {
            Response::returnJSON( 400, [ 
                'err_string' => 'No DataTable Found', 
            ]);
        }

        //  Get The Row Data For The Columns
        $tableDataCollection = $this->getCollection($tableHash);

         // Retrieve all rows in the table
         $rows = array_map(function ($row) {
            $row['_id'] = (string) $row['_id']; // Convert ObjectId to string
            return $row;
        }, iterator_to_array($tableDataCollection->find()));
        
        return [
            '_id'           => (string)$metaData['_id'],
            'id'            => $tableHash,               // This will be the collection ID
            'tableName'     => $metaData['tableName'],   // The table name
            'columns'       => $metaData['columns'],     // Columns array
            'labelColumns'  => $metaData['labelColumns'],
            'data'          => $rows                     // Directly returning the rows as data
        ];

    }

    /**
     * We Create A New DataTables For Usage. We Only Create The MetaData Table Here
     * So We Can Use As A Reference For Later To Store Documents
     * 
     * @param array $DATA[
     *      name: The Name Of The Table
     *      columns: An Array Of Column Names String Only
     * ]
     *  
     */
    public function createNewDataTable( $DATA ) {

        //  Table Hash ID For Usage Later
        $tableHash = UniqID::genUID( 'dt', 10 );

        //  Generate Unique Hash Columns So We Know Which Values To Update Later
        $metaData = [
            'resourceType'  => 'dataTable',
            'tableId'       => $tableHash,
            'tableName'     => $DATA['name'],
            'columns'       => $this->prepareDataTableColumns( $DATA['columns'] ),
        ];

        $metaData['labelColumns'] = [];

        if (isset($metaData['columns'][0]['id'])) {
            $metaData['labelColumns'][] = $metaData['columns'][0]['id'];
        }

        if (isset($metaData['columns'][1]['id'])) {
            $metaData['labelColumns'][] = $metaData['columns'][1]['id'];
        }

        //  Insert Into MetaData Collection
        $metadataCollection = $this->getDataTableMetaDataCollection();
        
        $this->insertData( $metadataCollection, $metaData );

    }

    //  Should Be An Array Of Strings For The Column Names
    public function prepareDataTableColumns($columns){

        $COLUMNS = [];

        foreach( $columns as $name ) {

            $COLUMNS[] =[
                'id'            => UniqID::genUID( 'dtc', 10 ), //  Identifier For Column
                'label'         => $name,                       //  Name Of Column
                'locked'        => false,                       //  Protects A Column For Deletion
                'is_xt'         => false,                       //  Is This A Xentask Column? These Should Only Be Deletable By Us EX: contacts table  
            ];

        }
        
        return $COLUMNS;

    }

    /**
     *  This Is The Build The Contacts Table Unique To The Custom Fields Only
     */
    public function newContactsTable() {

        //  First Insert The Metadata For The Table
        $metaData = [
            'resourceType'  => 'dataTable',
            'tableId'       => 'contacts',
            'tableName'     => 'Contacts',
            'columns'       => ['First Name','Last Name','Email','Phone'],  // The Basic Columns We Want
        ]; 

        $metaData['columns'] = $this->prepareDataTableColumns( $metaData['columns'] );

        //  Default To The First Two Columns 
        $metaData['labelColumns'] = [
            $metaData['columns'][0]['id'],
            $metaData['columns'][1]['id'],
        ];

        //  These Are The Default Columns For The Contacts Table And Should Not Be Deletable
        foreach( $metaData['columns'] as $column => $data ){

            $metaData['columns'][$column]['is_xt'] = true;

        }

        $metadataCollection = $this->getDataTableMetaDataCollection();

        $result = $this->insertData( $metadataCollection, $metaData );

        $metaData['_id'] = (string)$result->getInsertedId();
        
        return $metaData;

    }

    public function contactsTableExists(){

        $metaDataCollection = $this->getDataTableMetaDataCollection();

        $result = $metaDataCollection->findOne(['tableId' => 'contacts']);

        return $result !== null;

    }

    public function updateColumn( $tableHash, $DATA ){

        $docId      = $this->convertToMongoId($DATA['docId']);
        $columnId   = $DATA['columnId'];
        $value      = $DATA['value'];
        $isHeader   = empty( $DATA['isHeader'] ) ? false : $DATA['isHeader'];

        //  Update The Header Columns Array
        if(  $isHeader == true ) {

            $filter = [
                "_id" => $docId,  
                "columns.id" => $columnId // Find the column inside the array
            ];
            
            $update = [
                '$set' => [
                    "columns.$.label" => $value 
                ]
            ];

            $collection = $this->getDataTableMetaDataCollection();

        } else {

            // Update The Columns Normally
            $filter = [
                "_id" => $docId, 
            ];
            
            $update = [
                '$set' => [
                    $columnId => $value // Dynamically update the correct column field
                ]
            ];

            $collection = $this->getCollection( $tableHash );

        }

        $result = $collection->updateOne($filter, $update);

        return [
            'matched' => $result->getMatchedCount(),
            'modified' => $result->getModifiedCount(),
            'upserted_id' => $result->getUpsertedId(),
        ];

    }

    /**
     * Adds A New Blank Row Into A Collection
     */
    public function addNewRow( $tableHash ) {
        
        $metaDataCollection = $this->getDataTableMetaDataCollection();

        $metaData   = $metaDataCollection->findOne( ['tableId' => $tableHash ] );

        $COLUMNS    = $metaData['columns'];

        //  Create A Row Identifier
        $ROW['rowId'] = UniqID::genUID( 'dtr', 10 );

        //  Populate Row With Default Data
        foreach( $COLUMNS as $column ) {

            $ROW[ $column['id'] ] = '';

        }

        $collection = $this->getCollection( $tableHash );

        $collection->insertData( $collection, $ROW );

        return $ROW['rowId'];
    }

    /**
     * Add New Row Data
     */
    public function addRow( $tableHash, $rowData ) {
        
        $metaDataCollection = $this->getDataTableMetaDataCollection();

        $metaData   = $metaDataCollection->findOne( ['tableId' => $tableHash ] );

        //  Create A Row Identifier
        $ROW['rowId'] = UniqID::genUID( 'dtr', 10 );

        $ROW = array_merge( $ROW, $rowData );

        $collection = $this->getCollection( $tableHash );

        $res = $this->insertData( $collection, $ROW );

        //  Get The Last Insert ID Of Document
        $insertedId = $res->getInsertedId();

        return (string)$insertedId;
    }

    /**
     * Delete Document(s)
     * 
     * @param string $tableHash - The Table We're Looking For
     * @param mixed[array|string] $rowData - Pass In Many document _id's or a single _id
     */
    public function deleteRows( $tableHash, $rowData ) {

        $collection = $this->getCollection( $tableHash );
        $this->deleteData( $collection, $rowData );
    
    }

    public function addColumn( $tableHash, $docId, $label = "" ) {

        $collection = $this->getDataTableMetaDataCollection();

        $newColumn = [
            'id'    => UniqID::genUID( 'dtc', 10 ),     //  Identifier For Column
            'label' => $label,                              //  Name Of Column
            'is_xt' => false,                           //  Is This A XenTask Column?  
            'locked' => false                           //  Protect A Column From Being Deleted
        ];

        $docId = $this->convertToMongoId($docId);

        $filter = [
            "_id" => $docId, 
        ];

        $update = [
            '$push' => [
                'columns' => $newColumn
            ]
        ];

        $result = $collection->updateOne( $filter, $update );

        return !empty( $result->getModifiedCount() ) ? $newColumn : null;

    }

    public function deleteColumn( $docId, $columnId ) {

        $filter = [
            "_id" => $this->convertToMongoId($docId) // Ensure it's a MongoDB ObjectId if needed
        ];
        
        $update = [
            '$pull' => [
                "columns" => ["id" => $columnId] // Remove the object where `id` matches `$columnId`
            ]
        ];
        
        $collection = $this->getDataTableMetaDataCollection();

        $result = $collection->updateOne($filter, $update);

        return [
            'matched' => $result->getMatchedCount(),
            'modified' => $result->getModifiedCount(),
            'upserted_id' => $result->getUpsertedId(),
        ];

    }

    public function importData( $tableHash, $FILES ) {

        //  First Lets Get The DataTable's Current Columns If Any Exists
        $metaDataCollection = $this->getDataTableMetaDataCollection();

        $metaData   = $metaDataCollection->findOne( [ 'tableId' => $tableHash ] );

        $COLUMNS    = (array)$metaData['columns'];

        $col_count = count($COLUMNS);

        if (!isset($FILES['file']) || !is_uploaded_file($FILES['file']['tmp_name'])) {
            return ['error' => 'Invalid file upload.'];
        }
    
        $filePath = $FILES['file']['tmp_name']; // Get temporary file path
    
        $handle = fopen($filePath, 'r'); // Open the uploaded file

        if ($handle === false) {
            return ['error' => 'Failed to open CSV file.'];
        }
    
        $header = fgetcsv($handle); // Read the first row (header)
        
        if (!$header) {
            fclose($handle);
            Response::returnJSON( 400, [
                'msg' => 'Missing Header Row Or CSV Is Empty.', 
            ]);
        }
    
        // Identify extra columns
        $csv_columns = count($header);

        $column_diff = $csv_columns - $col_count;

        //  The User Uploaded A CSV File With Wrong Number Of Columns
        if( $column_diff < 0 ) {

            Response::returnJSON( 400, [
                'msg' => 'Number Of Columns Are Less Than Expected.', 
            ]);

        } elseif(  $column_diff > 0 ) {

            // Get the last N columns from the header
            $extraColumns = array_slice( $header, -$column_diff );

            //  Prepare The Co.umns In The Correct Format 
            $NEW_COLUMNS = $this->prepareDataTableColumns( $extraColumns );
            
            //  Push Each New Column To The MetaData Table First
            $filter = [
                "_id" => $metaData['_id']
            ];
            
            $update = [
                '$push' => [
                    "columns" => [
                        '$each' => $NEW_COLUMNS
                    ]
                ]
            ];
            
            $result = $metaDataCollection->updateOne($filter, $update);
          
            if( $result->getModifiedCount() > 0 ) {

                if (is_array($COLUMNS) && is_array($NEW_COLUMNS)) {
                    $COLUMNS = array_merge($COLUMNS, $NEW_COLUMNS);
                } else {
                    Response::returnJSON( 400, [
                        'msg' => 'Could Not Insert New Columns To Table.', 
                    ]);
                }

            } else {

                Response::returnJSON( 400, [
                    'msg' => 'Could Not Insert New Columns To Table.',
                ]);

            }
        }
   
        // Map CSV Data to Column IDs
        $parsedRows = [];

        //  Loop Through Each Row
        while (($row = fgetcsv($handle)) !== false) {

            $mappedRow = [];
            
            // Loop through each column and assign data to its corresponding column ID
            foreach ( $COLUMNS as $index => $column ) {
                $columnId = $column['id'];              // Get the unique ID of the column
                $mappedRow[$columnId] = $row[$index];   // Assign data to column ID
            }

            $parsedRows[] = $mappedRow;
        }

        fclose($handle); // Close the file

        //  Insert The Data Into The Collection Now
        $collection = $this->getCollection( $tableHash );

        $res = $this->insertData( $collection, $parsedRows );

    }
    
    //  Selects The DataTable MetaData Collection
    public function getDataTableMetaDataCollection() {
        $collectionFullName = "{$this->workspace_hash}_{$this->component_name}_metadata";
        return $this->db->selectCollection($collectionFullName);
    }

}
