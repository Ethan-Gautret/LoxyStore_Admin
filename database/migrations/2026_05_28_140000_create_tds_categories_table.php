<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tds_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 200);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $categories = [
            ['code' => 'GENERAL', 'name' => 'Général'],
            ['code' => 'COMPORT', 'name' => 'Laptops'],
            ['code' => 'COMDESK', 'name' => 'Desktops'],
            ['code' => 'COMSER', 'name' => 'Servers'],
            ['code' => 'INDUSTSYS', 'name' => 'Industrial Handhelds'],
            ['code' => 'COMHAND', 'name' => 'Tablets & e-Books'],
            ['code' => 'COMEBOOK', 'name' => 'e-Books'],
            ['code' => 'CALCS', 'name' => 'Calculators & Dictionary'],
            ['code' => 'POSSYSTEM', 'name' => 'POS Systems'],
            ['code' => 'COMACCSER', 'name' => 'Accessories & Services'],
            ['code' => 'TDCONFIG', 'name' => 'TD Configuration Services'],
            ['code' => 'PERMONIT', 'name' => 'Monitors'],
            ['code' => 'KEYBMICE', 'name' => 'Keyboards & Mice'],
            ['code' => 'PERBARCO', 'name' => 'Bar Code Readers'],
            ['code' => 'OTHPERIFS', 'name' => 'Other Peripheral Accessories'],
            ['code' => 'PERUPS', 'name' => 'UPS'],
            ['code' => 'FLASHMEMO', 'name' => 'Flash Memory'],
            ['code' => 'PERSTOR', 'name' => 'Storage'],
            ['code' => 'PERREMME', 'name' => 'Removable Media'],
            ['code' => 'PRICAME', 'name' => 'Print Cartridges & Media'],
            ['code' => 'PRISFD', 'name' => 'Single Function Devices'],
            ['code' => 'PRIMULTFU', 'name' => 'Multifunction Devices'],
            ['code' => 'PRILAFOR', 'name' => 'Large Format Printers'],
            ['code' => 'PRI3DPRI', 'name' => '3D Printing'],
            ['code' => 'PRISERSUP', 'name' => 'Printer Service & Support'],
            ['code' => 'PRIPARMAI', 'name' => 'Printer Accessories'],
            ['code' => 'SOFTDESK', 'name' => 'Desktop Applications'],
            ['code' => 'SOFTOS', 'name' => 'Operating Systems'],
            ['code' => 'SOFTDESIG', 'name' => 'Design Software'],
            ['code' => 'SOFTGRA', 'name' => 'Graphics & Media'],
            ['code' => 'SOFTSMB', 'name' => 'ERP for Small Business'],
            ['code' => 'SOFTSEC', 'name' => 'Security Software'],
            ['code' => 'SOFTUTIL', 'name' => 'Utilities Software'],
            ['code' => 'SOFTDEVTO', 'name' => 'Developer Tools'],
            ['code' => 'SOFTGAMES', 'name' => 'Games'],
            ['code' => 'SOFTSERVA', 'name' => 'Server Applications'],
            ['code' => 'SOFTSTOR', 'name' => 'Storage Software'],
            ['code' => 'SOFTNW', 'name' => 'Networking Software'],
            ['code' => 'SOFTOTH', 'name' => 'Other Software'],
            ['code' => 'SOFTSS', 'name' => 'Service & Support'],
            ['code' => 'SOFTCADR', 'name' => 'SOFTCADR'],
            ['code' => 'SOFTCADHD', 'name' => 'SOFTCADHD'],
            ['code' => 'SOFTCADAC', 'name' => 'SOFTCADAC'],
            ['code' => 'SOFTCADME', 'name' => 'SOFTCADME'],
            ['code' => 'SOFTCADMF', 'name' => 'SOFTCADMF'],
            ['code' => 'NWLAN', 'name' => 'LAN'],
            ['code' => 'NWWIRELSS', 'name' => 'Wireless Networking'],
            ['code' => 'NWPOWLINE', 'name' => 'Powerline'],
            ['code' => 'NWCABLES', 'name' => 'Networking Cables'],
            ['code' => 'NWSTORA', 'name' => 'Network Storage'],
            ['code' => 'NWBACKUP', 'name' => 'Backup'],
            ['code' => 'NWRACKING', 'name' => 'Racking & Cabinets'],
            ['code' => 'NWKVM', 'name' => 'KVM Switches'],
            ['code' => 'NWSECURE', 'name' => 'Security Networking'],
            ['code' => 'NWPHYSSEC', 'name' => 'Physical Security'],
            ['code' => 'NWACCESO', 'name' => 'Networking Accessories'],
            ['code' => 'NWSS', 'name' => 'NWSS'],
            ['code' => 'COMPCOMPU', 'name' => 'Computer Components'],
            ['code' => 'COMBATT', 'name' => 'Batteries'],
            ['code' => 'COMCABACC', 'name' => 'Cables & Adapters'],
            ['code' => 'TELMOBILE', 'name' => 'Mobile Phones'],
            ['code' => 'TELSIM', 'name' => 'SIM Cards'],
            ['code' => 'TELMOBSW', 'name' => 'Mobile Software'],
            ['code' => 'TELACC', 'name' => 'Phone Accessories'],
            ['code' => 'TELWEARAB', 'name' => 'Wearables'],
            ['code' => 'TELGPS', 'name' => 'GPS'],
            ['code' => 'TELNAVACC', 'name' => 'Navigation Accessories'],
            ['code' => 'TELALERT', 'name' => 'Alert GPS Systems'],
            ['code' => 'TELALARM', 'name' => 'Alarm Systems'],
            ['code' => 'TELWALKTA', 'name' => 'Walkie-Talkies'],
            ['code' => 'TELIP', 'name' => 'IP Telephony'],
            ['code' => 'MIXREAL', 'name' => 'Mixed Reality'],
            ['code' => 'URBMOBIL', 'name' => 'Urban Mobility'],
            ['code' => 'IOTCONSUM', 'name' => 'Consumer IoT'],
            ['code' => 'IOTINDSEN', 'name' => 'Industrial IoT Sensors'],
            ['code' => 'IOTINDNW', 'name' => 'Industrial IoT Networking'],
            ['code' => 'IOTINDCON', 'name' => 'Industrial IoT Connectivity'],
            ['code' => 'IOTINDDAT', 'name' => 'Industrial IoT Data'],
            ['code' => 'TELTEL', 'name' => 'Telephones'],
            ['code' => 'AVUCC', 'name' => 'UC & Collaboration'],
            ['code' => 'AVCAM', 'name' => 'Cameras'],
            ['code' => 'AVAUDIO', 'name' => 'Audio'],
            ['code' => 'AVPROD', 'name' => 'Professional Displays'],
            ['code' => 'AVDIG', 'name' => 'Digital Signage'],
            ['code' => 'AVINT', 'name' => 'Interactive Products'],
            ['code' => 'AVIPROJEC', 'name' => 'Projectors'],
            ['code' => 'AVTELEVI', 'name' => 'Television'],
            ['code' => 'AVMOUNT', 'name' => 'Mounting Solutions'],
            ['code' => 'AVCONT', 'name' => 'Connectivity & Control'],
            ['code' => 'AVOTHER', 'name' => 'Other AV'],
            ['code' => 'AVSERVICE', 'name' => 'AV Services'],
            ['code' => 'AVGAME', 'name' => 'Gaming'],
            ['code' => 'HHCARE', 'name' => 'Personal Care'],
            ['code' => 'HHLED', 'name' => 'LED Lighting'],
            ['code' => 'HHCOFFMA', 'name' => 'Coffee Makers'],
            ['code' => 'HHTOAST', 'name' => 'Toasters & Grills'],
            ['code' => 'HHKITCH', 'name' => 'Small Kitchen Appliances'],
            ['code' => 'HHIRONS', 'name' => 'Irons'],
            ['code' => 'HHCLEAN', 'name' => 'Vacuum Cleaners'],
            ['code' => 'HHCOOLER', 'name' => 'Heaters & Coolers'],
        ];

        $now = now();
        $rows = [];

        foreach ($categories as $index => $category) {
            $rows[] = [
                'code' => $category['code'],
                'name' => $category['name'],
                'sort_order' => $index + 1,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tds_categories')->upsert(
            $rows,
            ['code'],
            ['name', 'sort_order', 'active', 'updated_at']
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_categories');
    }
};