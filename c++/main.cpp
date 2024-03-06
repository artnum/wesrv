#include "include/msg.h"
#include "include/srv.h"
#include <iostream>
#include <string>

using namespace std;
using namespace wesrv;

int main(int argc, char ** argv) {
    Srv* srv = new Srv("127.0.0.1", 8533, "127.0.0.1", 8533, "xQgtACG7jMtjwhXmdKZvwc8RH3uGzXPGTXsfNnaA7M4RBAjL");

    srv->init();
    return 0;
}